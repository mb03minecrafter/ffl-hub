<?php

namespace FFLHub;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Admin\Orders\OrderPlacementMetaBox;
use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\DealerFulfilledJobsPage;
use FFLHub\Admin\Pages\DistributorProductsPage;
use FFLHub\Admin\Pages\FFLImporterPage;
use FFLHub\Admin\ProductMeta\BOMMetaBox;
use FFLHub\Admin\ProductMeta\OrderFFLPanel;
use FFLHub\Admin\ProductMeta\ProductMetaBox;
use FFLHub\Admin\WPCronWarning;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;
use FFLHub\Checkout\Compliance\CartCompliance;
use FFLHub\Checkout\Compliance\FFLRequiredCartExtension;
use FFLHub\Checkout\Fields\CheckoutFields;
use FFLHub\Checkout\Map\CheckoutMap;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\FFL\API\FFLApi;
use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Product\MapPriceVisibility;
use FFLHub\Settings\Options;
use FFLHub\Settings\SettingsRegistrar;
use FFLHub\Shipping\Wordpress\ShippingRegistrar;

/**
 * Main plugin bootstrapper for FFL Hub.
 */
final class Plugin
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    public FFLSchema $ffl_table_schema;
    public FFLTable $ffl_table;
    public BOMSchema $bom_table_schema;
    public BOMTable $bom_table;

    public FFLApi $ffl_api;

    public DistributorHandler $distributor_handler;

    // Admin-only
    public AdminPage $admin_page;
    public FFLImporterPage $ffl_importer_page;
    public DistributorProductsPage $distributor_products_page;
    public DealerFulfilledJobsPage $dealer_fulfilled_jobs_page;
    public OrderPlacementMetaBox $order_placement_metabox;

    // Frontend-only
    public CheckoutFields $checkout_fields;

    // Always-on
    public CartCompliance $cart_compliance;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        

        // -----------------------------------------------------------------
        // Always-on bootstrap
        // -----------------------------------------------------------------
        SettingsRegistrar::init();

        $this->ffl_table_schema = new FFLSchema();

        $this->ffl_table = new FFLTable($this->ffl_table_schema);

        $this->bom_table_schema = new BOMSchema();
        $this->bom_table = new BOMTable($this->bom_table_schema);

        $this->ffl_api = new FFLApi($this->ffl_table);
        $this->ffl_api->register();

        $this->distributor_handler = new DistributorHandler($this->ffl_table);
        $this->distributor_handler->register_runtime_services();

        ShippingRegistrar::init();

        MapPriceVisibility::init();

        $this->cart_compliance = new CartCompliance($this->ffl_table, $this->distributor_handler);

        $this->cart_compliance->register();

        FFLRequiredCartExtension::init();

        // -----------------------------------------------------------------
        // Admin-only initialization
        // -----------------------------------------------------------------
        if (is_admin()) {
            WPCronWarning::init();

            $this->admin_page = new AdminPage($this->distributor_handler);
            $this->admin_page->register();

            $this->distributor_products_page = new DistributorProductsPage($this->distributor_handler);
            $this->distributor_products_page->register();

            $this->dealer_fulfilled_jobs_page = new DealerFulfilledJobsPage($this->distributor_handler->ordering_jobs_table);
            $this->dealer_fulfilled_jobs_page->register();

            $this->order_placement_metabox = new OrderPlacementMetaBox($this->distributor_handler->ordering_jobs_table);
            $this->order_placement_metabox->register();

            $this->ffl_importer_page = new FFLImporterPage($this->ffl_table);
            $this->ffl_importer_page->register();

            OrderFFLPanel::init();

            ProductMetaBox::init();
            BOMMetaBox::init($this->distributor_handler);

            return;
        }

        // -----------------------------------------------------------------
        // Frontend-only initialization (checkout UX)
        // -----------------------------------------------------------------
        $this->checkout_fields = new CheckoutFields($this->ffl_table);
        $this->checkout_fields->register();

        CheckoutMap::init();

    }

    public static function activate(): void
    {
        Options::init_defaults();
        CategoryInstaller::install_default_categories();

        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);
        $ffl_table->createTables();

        $bom_table_schema = new BOMSchema();
        $bom_table        = new BOMTable($bom_table_schema);
        $bom_table->createTables();

        $handler = new DistributorHandler($ffl_table);
        $handler->on_activate();
    }

    public static function deactivate(): void
    {
        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);

        $handler = new DistributorHandler($ffl_table);
        $handler->on_deactivate();
    }
}
