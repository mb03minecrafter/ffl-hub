<?php

namespace FFLHub;

if (! defined('ABSPATH')) {
    exit;
}


use FFLHub\Admin\WPCronWarning;
use FFLHub\Admin\DistributorProductsPage;
use FFLHub\Admin\AdminPage;
use FFLHub\Admin\FFLImporterPage;
use FFLHub\Admin\OrderFFLPanel;
use FFLHub\Admin\ProductMetaBox;

use FFLHub\Distributor\DistributorInterface;
use FFLHub\Distributor\RSR\DistributorRSR;
use FFLHub\Distributor\RSR\Cron\RSRFulfillmentCron;
use FFLHub\Distributor\RSR\Cron\RSRInventoryCron;
use FFLHub\Distributor\RSR\Tables\RSRFulfillmentTable;


use FFLHub\Distributor\Lipseys\DistributorLipseys;


use FFLHub\Checkout\CheckoutFields;
use FFLHub\Checkout\CheckoutMap;
use FFLHub\Checkout\FFLRequiredCartExtension;



use FFLHub\Distributor\Lipseys\Cron\LipseysFulfilmentCron;
use FFLHub\Distributor\Lipseys\Cron\LipseysPricingQuantityCron;
use FFLHub\Distributor\Lipseys\Tables\LipseysFulfillmentTable;
use FFLHub\Distributor\Product\DistributorProductSync;

use FFLHub\FFL\FFLApi;
use FFLHub\FFL\Tables\FFLTable;


use FFLHub\Product\CategoryInstaller;
/**
 * Main plugin class for FFL Hub.
 */
class Plugin
{
    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Registered distributor objects.
     *
     * @var DistributorInterface[]
     */
    private $distributors = array();

    /**
     * Get the single instance of the class.
     *
     * @return Plugin
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor: set up includes, services, and hooks.
     *
     * Private because we want to enforce the singleton via instance().
     */
    private function __construct()
    {
        // 2. Register distributor instances.
        $this->register_distributors();

        // 3. Initialize feature/services classes.
        $this->register_services();

        // 4. Hook into WordPress admin.
        $this->register_hooks();
    }

    /**
     * Instantiate and store distributor objects.
     */
    private function register_distributors(): void
    {
        $this->distributors = array(
            // RSR distributor.
            new DistributorRSR(),

            // Lipsey's distributor.
            new DistributorLipseys(),
        );
    }

    /**
     * Initialize feature/service classes.
     */
    private function register_services(): void
    {
        // Cron warning.
        WPCronWarning::init();

        // RSR cron jobs.
        RSRFulfillmentCron::init();
        RSRInventoryCron::init();

        // Lipsey's cron jobs.
        LipseysFulfilmentCron::init();
        LipseysPricingQuantityCron::init();

        // Managed product sync cron.
        DistributorProductSync::init();

        // Woo store API integration.
        FFLRequiredCartExtension::init();

        // FFL importer + REST API + admin order panel.
        FFLImporterPage::init();
        FFLApi::init();
        OrderFFLPanel::init();

        // Admin settings page & subpages.
        AdminPage::init();
        DistributorProductsPage::init();

        // Checkout fields + map UI.
        CheckoutFields::init();
        CheckoutMap::init();

        // Product meta box.
        ProductMetaBox::init();
    }

    /**
     * Register WordPress hooks (admin).
     *
     * NOTE: In this pattern, admin pages register their own menus,
     * but we still give distributors a chance to register settings.
     */
    private function register_hooks(): void
    {
        // Distributors still use the Settings API via admin_init.
        add_action('admin_init', array($this, 'register_distributor_settings'));
    }

    /**
     * Let each distributor register its own settings.
     */
    public function register_distributor_settings(): void
    {
        foreach ($this->distributors as $dist) {
            $dist->register_settings();
        }
    }

    /**
     * Render the main settings page.
     * Delegates to the FFLHub_Admin_Page renderer.
     */
    public function render_settings_page(): void
    {
        AdminPage::render($this->distributors);
    }

    /**
     * Get a distributor by its ID (e.g. 'lipseys', 'rsr').
     *
     * @param string $id
     * @return DistributorInterface|null
     */
    public function get_distributor_by_id(string $id): ?DistributorInterface
    {
        foreach ($this->distributors as $dist) {
            if ($dist->get_id() === $id) {
                return $dist;
            }
        }

        return null;
    }

    /**
     * Get all registered distributor instances.
     *
     * @return \DistributorInterface[]
     */
    public function get_distributors(): array
    {
        if (! isset($this->distributors) || ! is_array($this->distributors)) {
            return array();
        }

        return $this->distributors;
    }

    /**
     * Create all required database tables.
     *
     * Scoped to this class; used during plugin activation.
     */
    private static function create_tables(): void
    {
        // FFL table.
        FFLTable::create_table();

        // RSR fulfillment table.
        RSRFulfillmentTable::create_tables();

        // Lipsey's fulfillment table.
        LipseysFulfillmentTable::create_tables();
    }

    private static function destroy_tables(): void
    {
        // Intentionally left blank for now.
    }

    /**
     * Plugin activation callback.
     *
     * This is hooked from the main plugin file via:
     * register_activation_hook( FFLHUB_PLUGIN_FILE, array( 'FFLHub_Plugin', 'activate' ) );
     */
    public static function activate(): void
    {
        // Create required tables.
        self::create_tables();

        if (get_option('fflhub_payment_processor_fee_percent', null) === null) {
            add_option('fflhub_payment_processor_fee_percent', '2.9'); // 2.9% default
        }

        if (get_option('fflhub_global_markup', null) === null) {
            add_option('fflhub_global_markup', '10.0'); // 10% default
        }

        CategoryInstaller::install_default_categories();
    }

    /**
     * Plugin deactivation callback.
     *
     * This is hooked from the main plugin file via:
     * register_deactivation_hook( FFLHUB_PLUGIN_FILE, array( 'FFLHub_Plugin', 'deactivate' ) );
     */
    public static function deactivate(): void
    {
        // Cron classes clean themselves up.
        RSRFulfillmentCron::on_deactivation();
        RSRInventoryCron::on_deactivation();
        LipseysFulfilmentCron::on_deactivation();
        LipseysPricingQuantityCron::on_deactivation();
        DistributorProductSync::deactivate();
    }
}

/**
 * Backwards compatibility:
 *
 * Allow legacy references to the global FFLHub_Plugin class name,
 * e.g. register_activation_hook(..., array('FFLHub_Plugin', 'activate')).
 */
\class_alias(__NAMESPACE__ . '\\Plugin', 'FFLHub_Plugin');
