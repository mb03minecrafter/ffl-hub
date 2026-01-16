<?php

namespace FFLHub;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Settings\Options;
use FFLHub\Settings\SettingsRegistrar;

use FFLHub\Admin\WPCronWarning;
use FFLHub\Admin\DistributorProductsPage;
use FFLHub\Admin\AdminPage;
use FFLHub\Admin\FFLImporterPage;
use FFLHub\Admin\OrderFFLPanel;
use FFLHub\Admin\ProductMetaBox;

use FFLHub\Distributor\DistributorHandler;



use FFLHub\Checkout\CheckoutFields;
use FFLHub\Checkout\CheckoutMap;
use FFLHub\Checkout\FFLRequiredCartExtension;



use FFLHub\FFL\FFLApi;
use FFLHub\FFL\Tables\FFLTable;

use FFLHub\Shipping\ShippingRegistrar;



use FFLHub\Product\CategoryInstaller;
use FFLHub\Product\MapPriceVisibility;

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

        SettingsRegistrar::init();


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

        ShippingRegistrar::init();

        MapPriceVisibility::init();



        // 4. Hook into WordPress admin.
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
        
    }
}

