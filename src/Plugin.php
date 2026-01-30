<?php

namespace FFLHub;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Admin\Orders\OrderPlacementMetaBox;
use FFLHub\Settings\Options;
use FFLHub\Settings\SettingsRegistrar;

use FFLHub\Admin\WPCronWarning;

use FFLHub\Admin\Pages\DistributorProductsPage;
use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\FFLImporterPage;

use FFLHub\Admin\ProductMeta\OrderFFLPanel;
use FFLHub\Admin\ProductMeta\ProductMetaBox;


use FFLHub\Distributor\Core\DistributorHandler;



use FFLHub\Checkout\Fields\CheckoutFields;
use FFLHub\Checkout\Map\CheckoutMap;
use FFLHub\Checkout\Compliance\FFLRequiredCartExtension;
use FFLHub\Checkout\Compliance\CartCompliance;
use FFLHub\Distributor\Services\Orders\OrderPlacementOrchestrator;
use FFLHub\Distributor\Services\Orders\OrderTrashJobsService;
use FFLHub\Distributor\Services\Tables\OrderPlacementJobsTable;

use FFLHub\FFL\API\FFLApi;
use FFLHub\FFL\Tables\FFLTable;

use FFLHub\Shipping\Wordpress\ShippingRegistrar;



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
    public OrderPlacementOrchestrator $order_orchestrator;


    //Admin Classes
    public DistributorProductsPage $distributor_products_page;


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

    private function __construct()
    {
        // Always-on: settings, core services, async/job hooks
        SettingsRegistrar::init();

        $this->distributor_handler = new DistributorHandler();
        $this->distributor_handler->register_runtime_services();

        ShippingRegistrar::init();
        MapPriceVisibility::init();

        // Cart compliance typically affects frontend + Store API; keep always-on unless proven heavy
        CartCompliance::init();
        FFLRequiredCartExtension::init();

        // Order placement/jobs must be available in cron/AS contexts too
        $this->order_orchestrator = new OrderPlacementOrchestrator();
        $this->order_orchestrator->register();

        OrderTrashJobsService::init();

        // Context-specific: admin
        if (is_admin()) {
            WPCronWarning::init();
            AdminPage::init();
            
            $this->distributor_products_page = new DistributorProductsPage($this->distributor_handler);
            $this->distributor_products_page->register();

            FFLImporterPage::init();
            FFLApi::init();
            OrderFFLPanel::init();

            ProductMetaBox::init();
            OrderPlacementMetaBox::init();

            return; // optional: bail early to avoid accidental frontend init below
        }

        // Context-specific: frontend UI
        CheckoutFields::init();
        CheckoutMap::init();
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
        OrderPlacementJobsTable::create_table();
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
