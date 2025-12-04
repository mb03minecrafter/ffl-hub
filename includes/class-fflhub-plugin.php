<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for FFL Hub.
 */
class FFLHub_Plugin
{

    /**
     * Singleton instance.
     *
     * @var FFLHub_Plugin|null
     */
    private static $instance = null;

    /**
     * Registered distributor objects.
     *
     * @var FFLHub_Distributor_Interface[]
     */
    private $distributors = array();

    /**
     * Get the single instance of the class.
     *
     * @return FFLHub_Plugin
     */
    public static function instance()
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
        // 1. Load all class files used by the plugin.
        self::load_dependencies();

        // 2. Register distributor instances.
        $this->register_distributors();


        // 3. Initialize feature/services classes.
        $this->register_services();

        // 4. Hook into WordPress admin.
        $this->register_hooks();
    }

    /**
     * Include all core classes used by the plugin.
     *
     * Made static so it can be reused by activate()/deactivate()
     * without instantiating the whole plugin.
     */
    private static function load_dependencies(): void
    {
        // This file lives in the /includes/ directory.
        $base_path = plugin_dir_path(__FILE__);

        $files = array(
            // Table classes.
            'tables/class-fflhub-table-schema.php',
            'tables/class-fflhub-ffl-table.php',

            'tables/class-fflhub-rsr-fulfillment-table.php',
            'tables/class-fflhub-lipseys-fulfillment-table.php',

            // Store / checkout APIs.
            'class-fflhub-store-api.php',

            // FFL / product features.
            'products/class-fflhub-product-meta.php',
            'products/class-fflhub-category-schema.php',
            'products/class-fflhub-category-installer.php',

            //ffl api to get ffls from table
            'checkout/class-fflhub-ffl-api.php',

            // Distributor product payload.
            'distributors/class-fflhub-distributor-product-payload.php',

            // Distributor framework (now inside distributors/).
            'distributors/interface-fflhub-distributor.php',
            'distributors/class-fflhub-distributor-base.php',
            'distributors/class-fflhub-category-mapper.php',

            // Admin pages.
            'admin-pages/class-fflhub-admin-page.php',
            'admin-pages/class-fflhub-ffl-order-admin.php',
            'admin-pages/class-fflhub-admin-page-distributor-products.php',
            'admin-pages/class-fflhub-ffl-importer.php',
            'admin-pages/class-fflhub-product-meta-box.php',

            // Distributors.
            'distributors/rsr/class-fflhub-distributor-rsr.php',
            'distributors/rsr/class-fflhub-rsr-ftp-client.php',
            'distributors/rsr/class-fflhub-rsr-fulfillment-importer.php',

            // RSR cron + cron warning.
            'class-fflhub-wp-cron-warning.php',
            'distributors/rsr/cron/class-fflhub-rsr-fulfillment-cron.php',
            'distributors/rsr/cron/class-fflhub-rsr-inventory-cron.php',

            // Lipsey's.
            'distributors/lipseys/class-fflhub-distributor-lipseys.php',
            'distributors/lipseys/class-fflhub-lipseys-fulfillment-importer.php',

            // Lipsey's cron.
            'distributors/lipseys/cron/class-fflhub-lipseys-fulfillment-cron.php',
            'distributors/lipseys/cron/class-fflhub-lipseys-pricing-quantity-cron.php',

            // Checkout UI.
            'checkout/class-fflhub-checkout-fields.php',
            'checkout/class-fflhub-checkout-map.php',
        );

        foreach ($files as $file) {
            $path = $base_path . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Instantiate and store distributor objects.
     */
    private function register_distributors(): void
    {
        $this->distributors = array(
            // RSR distributor.
            new FFLHub_Distributor_RSR(),

            // Lipsey's distributor.
            new FFLHub_Distributor_Lipseys(),
        );
    }

    /**
     * Initialize feature/service classes.
     */
    private function register_services(): void
    {
        // Cron warning.
        FFLHub_WP_Cron_Warning::init();

        // RSR cron jobs.
        FFLHub_RSR_Fulfillment_Cron::init();
        FFLHub_RSR_Inventory_Cron::init();

        // Lipsey's cron jobs.
        FFLHub_Lipseys_Fulfillment_Cron::init();
        FFLHub_Lipseys_Pricing_Quantity_Cron::init();

        // Woo store API integration.
        FFLHub_Store_API::init();

        

        // FFL importer + REST API + admin order panel.
        FFLHub_FFL_Importer::init();
        FFLHub_FFL_API::init();
        FFLHub_FFL_Order_Admin::init();

        // Admin settings page & subpages.
        FFLHub_Admin_Page::init();
        FFLHub_Admin_Page_Distributor_Products::init();

        // Checkout fields + map UI.
        FFLHub_Checkout_Fields::init();
        FFLHub_Checkout_Map::init();



        // Somewhere in your plugin bootstrap:
        FFLHub_Product_Meta_Box::init();
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
        FFLHub_Admin_Page::render($this->distributors);
    }

    /**
     * Get a distributor by its ID (e.g. 'lipseys', 'rsr').
     *
     * @param string $id
     * @return FFLHub_Distributor_Interface|null
     */
    public function get_distributor_by_id(string $id): ?FFLHub_Distributor_Interface
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
     * @return FFLHub_Distributor_Interface[]
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
        FFLHub_FFL_Table::create_table();

        // RSR fulfillment table.
        FFLHub_RSR_Fulfillment_Table::create_tables();

        // Lipsey's fulfillment table.
        FFLHub_Lipseys_Fulfillment_Table::create_tables();
    }



    private static function destroy_tables(): void {}

    /**
     * Plugin activation callback.
     *
     * This is hooked from the main plugin file via:
     * register_activation_hook( FFLHUB_PLUGIN_FILE, array( 'FFLHub_Plugin', 'activate' ) );
     */
    public static function activate(): void
    {
        // Make sure all needed classes are loaded.
        // Only load what we need to create tables.
        require_once FFLHUB_PLUGIN_PATH . 'includes/tables/class-fflhub-table-schema.php';
        require_once FFLHUB_PLUGIN_PATH . 'includes/tables/class-fflhub-ffl-table.php';
        require_once FFLHUB_PLUGIN_PATH . 'includes/tables/class-fflhub-rsr-fulfillment-table.php';
        require_once FFLHUB_PLUGIN_PATH . 'includes/tables/class-fflhub-lipseys-fulfillment-table.php';

        require_once FFLHUB_PLUGIN_PATH . 'includes/products/class-fflhub-category-installer.php';
        require_once FFLHUB_PLUGIN_PATH . 'includes/products/class-fflhub-category-schema.php';


        // Create required tables.
        self::create_tables();



        if (get_option('fflhub_payment_processor_fee_percent', null) === null) {
            add_option('fflhub_payment_processor_fee_percent', '2.9'); // 2.9% default
        }



        if (get_option('fflhub_global_markup', null) === null) {
            add_option('fflhub_global_markup', '10.0'); // 2.9% default
        }

        FFLHub_Category_Installer::install_default_categories();



    }

    /**
     * Plugin deactivation callback.
     *
     * This is hooked from the main plugin file via:
     * register_deactivation_hook( FFLHUB_PLUGIN_FILE, array( 'FFLHub_Plugin', 'deactivate' ) );
     */
    public static function deactivate(): void
    {
        // Load dependencies so cron classes are available.
        self::load_dependencies();

        // Clean up cron jobs and other scheduled tasks.
        if (class_exists('FFLHub_RSR_Fulfillment_Cron')) {
            FFLHub_RSR_Fulfillment_Cron::on_deactivation();
        }

        if (class_exists('FFLHub_RSR_Inventory_Cron')) {
            FFLHub_RSR_Inventory_Cron::on_deactivation();
        }

        if (class_exists('FFLHub_Lipseys_Fulfillment_Cron')) {
            FFLHub_Lipseys_Fulfillment_Cron::on_deactivation();
        }

        if (class_exists('FFLHub_Lipseys_Pricing_Quantity_Cron')) {
            FFLHub_Lipseys_Pricing_Quantity_Cron::on_deactivation();
        }
    }
}
