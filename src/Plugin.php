<?php

namespace FFLHub;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Admin\Orders\OrderPlacementMetaBox;
use FFLHub\Admin\Orders\OrderFulfillmentModeBadge;
use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\DavidsonsFailedJobsPage;
use FFLHub\Admin\Pages\DealerFulfilledJobsPage;
use FFLHub\Admin\Pages\DistributorProductsPage;
use FFLHub\Admin\Pages\FFLImporterPage;
use FFLHub\Admin\Pages\LipseysCreditLimitPage;
use FFLHub\Admin\Pages\MapPolicyPage;
use FFLHub\Admin\Pages\ZandersCreditLimitPage;
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
use FFLHub\Distributor\Services\Cron\QuoteEmailJobsCronService;
use FFLHub\FFL\API\FFLApi;
use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Product\MapPriceVisibility;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Settings\Options;
use FFLHub\Settings\SettingsRegistrar;
use FFLHub\Shipping\Wordpress\ShippingRegistrar;

/**
 * Main plugin bootstrapper for FFL Hub.
 */
final class Plugin
{
    private const QUOTE_EMAIL_JOBS_SCHEMA_OPTION = 'fflhub_customer_quote_email_jobs_schema_v1';
    private const QUOTE_EMAIL_JOBS_SCHEMA_VERSION = '2';

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
    public DavidsonsFailedJobsPage $davidsons_failed_jobs_page;
    public ZandersCreditLimitPage $zanders_credit_limit_page;
    public LipseysCreditLimitPage $lipseys_credit_limit_page;
    public MapPolicyPage $map_policy_page;
    public OrderPlacementMetaBox $order_placement_metabox;
    public OrderFulfillmentModeBadge $order_fulfillment_mode_badge;

    // Frontend-only
    public CheckoutFields $checkout_fields;

    // Always-on
    public CartCompliance $cart_compliance;
    private QuoteEmailJobsCronService $quote_email_jobs_cron_service;

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
        self::ensure_quote_email_jobs_table();
        $this->quote_email_jobs_cron_service = new QuoteEmailJobsCronService();
        $this->quote_email_jobs_cron_service->register();

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

            $this->davidsons_failed_jobs_page = new DavidsonsFailedJobsPage($this->distributor_handler->ordering_jobs_table);
            $this->davidsons_failed_jobs_page->register();

            $this->zanders_credit_limit_page = new ZandersCreditLimitPage($this->distributor_handler->ordering_jobs_table);
            $this->zanders_credit_limit_page->register();

            $this->lipseys_credit_limit_page = new LipseysCreditLimitPage($this->distributor_handler->ordering_jobs_table);
            $this->lipseys_credit_limit_page->register();

            $this->map_policy_page = new MapPolicyPage();
            $this->map_policy_page->register();

            $this->order_placement_metabox = new OrderPlacementMetaBox($this->distributor_handler->ordering_jobs_table);
            $this->order_placement_metabox->register();

            $this->order_fulfillment_mode_badge = new OrderFulfillmentModeBadge($this->distributor_handler->ordering_jobs_table);
            $this->order_fulfillment_mode_badge->register();

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
        self::ensure_quote_email_jobs_table();
        $quote_email_jobs_cron = new QuoteEmailJobsCronService();
        $quote_email_jobs_cron->on_activation();

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
        $quote_email_jobs_cron = new QuoteEmailJobsCronService();
        $quote_email_jobs_cron->on_deactivation();

        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);

        $handler = new DistributorHandler($ffl_table);
        $handler->on_deactivate();
    }

    private static function ensure_quote_email_jobs_table(): void
    {
        $installed_version = (string) get_option(self::QUOTE_EMAIL_JOBS_SCHEMA_OPTION, '');
        if ($installed_version === self::QUOTE_EMAIL_JOBS_SCHEMA_VERSION) {
            return;
        }

        $schema = new QuoteEmailJobsSchema();
        $table = new QuoteEmailJobsTable($schema);
        $table->createTables();

        update_option(
            self::QUOTE_EMAIL_JOBS_SCHEMA_OPTION,
            self::QUOTE_EMAIL_JOBS_SCHEMA_VERSION,
            false
        );
    }
}
