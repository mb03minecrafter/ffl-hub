<?php

namespace FFLHub;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Admin\Orders\OrderPlacementMetaBox;
use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\DistributorProductsPage;
use FFLHub\Admin\Pages\FFLImporterPage;
use FFLHub\Admin\ProductMeta\OrderFFLPanel;
use FFLHub\Admin\ProductMeta\ProductMetaBox;
use FFLHub\Admin\WPCronWarning;
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
 *
 * Responsibilities:
 * - Register core settings and always-on services.
 * - Initialize shared dependencies (FFL table, distributor handler).
 * - Register admin-only UI/tools when in wp-admin.
 * - Register frontend-only UI (checkout fields/map) when not in admin.
 *
 * This class is instantiated as a singleton from the plugin entrypoint.
 */
final class Plugin
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * FFL table schema + table access layer.
     */
    public FFLSchema $ffl_table_schema;
    public FFLTable $ffl_table;

    /**
     * FFL API used for checkout autocomplete and other FFL lookups.
     */
    public FFLApi $ffl_api;

    /**
     * Distributor runtime registry/handler (RSR/Lipsey's/etc).
     */
    public DistributorHandler $distributor_handler;

    // -----------------------------
    // Admin-only services / pages
    // -----------------------------
    public FFLImporterPage $ffl_importer_page;
    public DistributorProductsPage $distributor_products_page;
    public OrderPlacementMetaBox $order_placement_metabox;

    // -----------------------------
    // Frontend-only services
    // -----------------------------
    public CheckoutFields $checkout_fields;

    // -----------------------------
    // Always-on services
    // -----------------------------
    public CartCompliance $cart_compliance;

    /**
     * Retrieve the singleton instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor for singleton.
     *
     * Notes on "static vs instance":
     * - We use static initializers for components that have no runtime dependencies
     *   (or can read dependencies via WP hooks/options safely).
     * - We use instance properties for components that require shared objects
     *   (e.g., DistributorHandler, FFLTable) to avoid "hidden globals" and to keep
     *   dependency flow explicit.
     */
    private function __construct()
    {
        // -----------------------------------------------------------------
        // Always-on bootstrap: settings, core services, runtime registries
        // -----------------------------------------------------------------
        SettingsRegistrar::init();

        $this->ffl_table_schema = new FFLSchema();
        $this->ffl_table        = new FFLTable($this->ffl_table_schema);

        // FFL API (used primarily at checkout to autocomplete / validate FFLs).
        $this->ffl_api = new FFLApi($this->ffl_table);
        $this->ffl_api->register();

        // Distributor handler + runtime services (tables, jobs, registries, etc).
        $this->distributor_handler = new DistributorHandler($this->ffl_table);
        $this->distributor_handler->register_runtime_services();

        // Shipping + MAP visibility are always-on.
        ShippingRegistrar::init();
        MapPriceVisibility::init();

        // Cart compliance affects both frontend and Store API behavior.
        $this->cart_compliance = new CartCompliance($this->ffl_table, $this->distributor_handler);
        $this->cart_compliance->register();

        // Store API/Cart extension to require FFL when applicable.
        FFLRequiredCartExtension::init();

        // -----------------------------------------------------------------
        // Admin-only initialization
        // -----------------------------------------------------------------
        if (is_admin()) {
            WPCronWarning::init();
            AdminPage::init();

            $this->distributor_products_page = new DistributorProductsPage($this->distributor_handler);
            $this->distributor_products_page->register();

            // Meta box needs ordering jobs table; sourced from handler.
            $this->order_placement_metabox = new OrderPlacementMetaBox($this->distributor_handler->ordering_jobs_table);
            $this->order_placement_metabox->register();

            $this->ffl_importer_page = new FFLImporterPage($this->ffl_table);
            $this->ffl_importer_page->register();

            OrderFFLPanel::init();
            ProductMetaBox::init();

            // Bail early: prevents accidental frontend initialization in admin.
            return;
        }

        // -----------------------------------------------------------------
        // Frontend-only initialization (checkout UX)
        // -----------------------------------------------------------------
        $this->checkout_fields = new CheckoutFields($this->ffl_table);
        $this->checkout_fields->register();

        CheckoutMap::init();
    }

    /**
     * Plugin activation callback.
     *
     * Creates required tables, sets default options, installs categories,
     * and lets each distributor provision its own required schema/state.
     */
    public static function activate(): void
    {
        Options::init_defaults();
        CategoryInstaller::install_default_categories();

        // Create core FFL tables.
        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);
        $ffl_table->createTables();

        // Allow distributor handler to provision its own tables/state.
        $handler = new DistributorHandler($ffl_table);
        $handler->on_activate();
    }

    /**
     * Plugin deactivation callback.
     *
     * Intended to disable scheduled tasks / runtime hooks safely.
     * (No destructive data operations here unless explicitly desired.)
     */
    public static function deactivate(): void
    {
        // Create core FFL tables.
        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);
        $ffl_table->createTables();

        $handler = new DistributorHandler($ffl_table);
        $handler->on_deactivate();
    }
}
