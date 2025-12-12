<?php

namespace FFLHub;

if (! defined('ABSPATH')) {
    exit;
}

use FFlHub\Settings\Options;

use FFLHub\Admin\WPCronWarning;
use FFLHub\Admin\DistributorProductsPage;
use FFLHub\Admin\AdminPage;
use FFLHub\Admin\FFLImporterPage;
use FFLHub\Admin\OrderFFLPanel;
use FFLHub\Admin\ProductMetaBox;

use FFLHub\Distributor\DistributorHandler;
use FFLHub\Distributor\Services\DistributorServiceHandler;



use FFLHub\Checkout\CheckoutFields;
use FFLHub\Checkout\CheckoutMap;
use FFLHub\Checkout\FFLRequiredCartExtension;



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

    public DistributorHandler $distributor_handler;

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


        $this->distributor_handler = new DistributorHandler();
        $this->distributor_handler->register_runtime_services();

        WPCronWarning::init(); //REWORK
        AdminPage::init();
        DistributorProductsPage::init();

       // Woo store API integration.
        FFLRequiredCartExtension::init(); //REWORK

        // FFL importer + REST API + admin order panel.
        FFLImporterPage::init(); //REWORK
        FFLApi::init(); //REWORK
        OrderFFLPanel::init(); //REWORK


        // Checkout fields + map UI.
        CheckoutFields::init(); //REWORK
        CheckoutMap::init(); //REWORK

        // Product meta box.
        ProductMetaBox::init(); //REWORK

        // 4. Hook into WordPress admin.
    }

    

    

   

    /**
     * Let each distributor register its own settings.
     */
    /*public function register_distributor_settings(): void
    {
        foreach ($this->distributor_handler->get_distributors() as $dist) {
            $dist->register_settings();
        }
    }*/

    

    

    /**
     * Create all required database tables.
     *
     * Scoped to this class; used during plugin activation.
     */
    private static function create_tables(): void
    {
        // FFL table.
        FFLTable::create_table();

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

        Options::init_defaults();

        CategoryInstaller::install_default_categories();

        $handler = new DistributorHandler();
        $handler->on_activate();


    }

    /**
     * Plugin deactivation callback.
     *
     * This is hooked from the main plugin file via:
     * register_deactivation_hook( FFLHUB_PLUGIN_FILE, array( 'FFLHub_Plugin', 'deactivate' ) );
     */
    public static function deactivate(): void
    {
        $handler = new DistributorHandler();
        $handler->on_deactivate();
        // Cron classes clean themselves up.
        //RSRFulfillmentCron::on_deactivation();
        //RSRInventoryCron::on_deactivation();
        //LipseysFulfilmentCron::on_deactivation();
        //LipseysPricingQuantityCron::on_deactivation();
        //DistributorProductSync::deactivate();
    }
}

/**
 * Backwards compatibility:
 *
 * Allow legacy references to the global FFLHub_Plugin class name,
 * e.g. register_activation_hook(..., array('FFLHub_Plugin', 'activate')).
 */
