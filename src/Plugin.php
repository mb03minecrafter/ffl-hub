<?php

namespace FFLHub;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Admin\Orders\OrderPlacementMetaBox;
use FFLHub\Admin\Orders\AuthorizeNetOrderRescueButton;
use FFLHub\Admin\Orders\OrderFulfillmentModeBadge;
use FFLHub\Admin\Orders\OrderCartComplianceMetaBox;
use FFLHub\Admin\Orders\OrderProfitAuditMetaBox;
use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\DavidsonsFailedJobsPage;
use FFLHub\Admin\Pages\DealerBatchOptimizerPage;
use FFLHub\Admin\Pages\DealerFulfilledJobsPage;
use FFLHub\Admin\Pages\DistributorBatchQueuePage;
use FFLHub\Admin\Pages\DistributorProductsPage;
use FFLHub\Admin\Pages\FFLImporterPage;
use FFLHub\Admin\Pages\GunDealsPerformancePage;
use FFLHub\Admin\Pages\LipseysCreditLimitPage;
use FFLHub\Admin\Pages\MapPolicyPage;
use FFLHub\Admin\Pages\ProductStatePage;
use FFLHub\Admin\Products\ProductDistributorColumns;
use FFLHub\Admin\Pages\RSRBatchQueuePage;
use FFLHub\Admin\Pages\UpcStockAlertsPage;
use FFLHub\Admin\Pages\ZandersCreditLimitPage;
use FFLHub\Admin\Products\GunDealsClickColumns;
use FFLHub\Admin\ProductMeta\BOMMetaBox;
use FFLHub\Admin\ProductMeta\OrderFFLPanel;
use FFLHub\Admin\ProductMeta\ProductMetaBox;
use FFLHub\Admin\WPCronWarning;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;
use FFLHub\Checkout\Compliance\CartCompliance;
use FFLHub\Checkout\Compliance\FFLRequiredCartExtension;
use FFLHub\Checkout\Fields\CheckoutFields;
use FFLHub\Checkout\KlaviyoDefaultEmailOptIn;
use FFLHub\Checkout\MailPoetAutoConfirmCronService;
use FFLHub\Checkout\MailPoetDefaultOptIn;
use FFLHub\Checkout\Map\CheckoutMap;
use FFLHub\Checkout\Notice\CaliforniaRelayNotice;
use FFLHub\Checkout\QuoteCartLinkHandler;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\Cron\QuoteEmailJobsCronService;
use FFLHub\Distributor\Services\Orders\Cron\LipseysCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\LipseysDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\SportsSouthCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\SportsSouthDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\ZandersCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\ZandersDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerAuditTable;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerConfig;
use FFLHub\Feeds\GunDeals\GunDealsAnalyticsStore;
use FFLHub\Feeds\GunDeals\GunDealsClickTracker;
use FFLHub\Feeds\GunDeals\GunDealsFeedCronService;
use FFLHub\FFL\API\FFLApi;
use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Order\WooShippingLabelCostSync;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Distributor\Services\OfferSync\ProductBestOffersStore;
use FFLHub\Product\MapPriceVisibility;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Product\StockAlerts\UpcStockAlertCronService;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Settings\Options;
use FFLHub\Settings\SettingsRegistrar;
use FFLHub\Shipping\Wordpress\ShippingRegistrar;
use FFLHub\Util\ActionSchedulerWebRunnerGuard;

/**
 * Main plugin bootstrapper for FFL Hub.
 */
final class Plugin
{
    private const QUOTE_EMAIL_JOBS_SCHEMA_OPTION = 'fflhub_customer_quote_email_jobs_schema_v1';
    private const QUOTE_EMAIL_JOBS_SCHEMA_VERSION = '3';

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
    public DealerBatchOptimizerPage $dealer_batch_optimizer_page;
    public DealerFulfilledJobsPage $dealer_fulfilled_jobs_page;
    public DavidsonsFailedJobsPage $davidsons_failed_jobs_page;
    public RSRBatchQueuePage $rsr_batch_queue_page;
    /** @var DistributorBatchQueuePage[] */
    public array $distributor_batch_queue_pages = [];
    public ZandersCreditLimitPage $zanders_credit_limit_page;
    public LipseysCreditLimitPage $lipseys_credit_limit_page;
    public MapPolicyPage $map_policy_page;
    public ProductStatePage $product_state_page;
    public GunDealsPerformancePage $gundeals_performance_page;
    public UpcStockAlertsPage $upc_stock_alerts_page;
    public OrderPlacementMetaBox $order_placement_metabox;
    public AuthorizeNetOrderRescueButton $authnet_order_rescue_button;
    public OrderCartComplianceMetaBox $order_cart_compliance_metabox;
    public OrderProfitAuditMetaBox $order_profit_audit_metabox;
    public OrderFulfillmentModeBadge $order_fulfillment_mode_badge;
    public ProductDistributorColumns $product_distributor_columns;
    public GunDealsClickColumns $gundeals_click_columns;

    // Frontend-only
    public CheckoutFields $checkout_fields;

    // Always-on
    public CartCompliance $cart_compliance;
    private QuoteEmailJobsCronService $quote_email_jobs_cron_service;
    private UpcStockAlertCronService $upc_stock_alert_cron_service;
    private MailPoetAutoConfirmCronService $mailpoet_auto_confirm_cron_service;
    private GunDealsFeedCronService $gundeals_feed_cron_service;

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
        ActionSchedulerWebRunnerGuard::init();

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

        $this->upc_stock_alert_cron_service = new UpcStockAlertCronService();
        $this->upc_stock_alert_cron_service->register();

        $this->mailpoet_auto_confirm_cron_service = new MailPoetAutoConfirmCronService();
        $this->mailpoet_auto_confirm_cron_service->register();

        $this->gundeals_feed_cron_service = new GunDealsFeedCronService();
        $this->gundeals_feed_cron_service->register();
        GunDealsAnalyticsStore::ensure_schema();

        ShippingRegistrar::init();

        OrderProfitAuditMeta::init();
        WooShippingLabelCostSync::init();

        MapPriceVisibility::init();
        GunDealsClickTracker::init();
        QuoteCartLinkHandler::init();

        $this->cart_compliance = new CartCompliance($this->ffl_table, $this->distributor_handler);

        $this->cart_compliance->register();

        FFLRequiredCartExtension::init();

        // -----------------------------------------------------------------
        // Admin-only initialization
        // -----------------------------------------------------------------
        if (is_admin()) {
            ProductStateStore::ensure_schema();
            DistributorOffersStore::ensure_schema();
            ProductBestOffersStore::ensure_schema();
            WPCronWarning::init();

            $this->admin_page = new AdminPage($this->distributor_handler);
            $this->admin_page->register();

            $this->distributor_products_page = new DistributorProductsPage($this->distributor_handler);
            $this->distributor_products_page->register();

            $this->dealer_fulfilled_jobs_page = new DealerFulfilledJobsPage($this->distributor_handler->ordering_jobs_table);
            $this->dealer_fulfilled_jobs_page->register();

            $this->dealer_batch_optimizer_page = new DealerBatchOptimizerPage(
                $this->distributor_handler->ordering_jobs_table,
                $this->distributor_handler
            );
            $this->dealer_batch_optimizer_page->register();

            $this->davidsons_failed_jobs_page = new DavidsonsFailedJobsPage($this->distributor_handler->ordering_jobs_table);
            $this->davidsons_failed_jobs_page->register();

            $this->rsr_batch_queue_page = new RSRBatchQueuePage(
                $this->distributor_handler->ordering_jobs_table,
                $this->distributor_handler
            );
            $this->rsr_batch_queue_page->register();

            foreach ($this->distributor_batch_queue_page_configs() as $config) {
                $page = new DistributorBatchQueuePage(
                    $this->distributor_handler->ordering_jobs_table,
                    $this->distributor_handler,
                    $config
                );
                $page->register();
                $this->distributor_batch_queue_pages[] = $page;
            }

            $this->zanders_credit_limit_page = new ZandersCreditLimitPage($this->distributor_handler->ordering_jobs_table);
            $this->zanders_credit_limit_page->register();

            $this->lipseys_credit_limit_page = new LipseysCreditLimitPage($this->distributor_handler->ordering_jobs_table);
            $this->lipseys_credit_limit_page->register();

            $this->map_policy_page = new MapPolicyPage();
            $this->map_policy_page->register();

            $this->product_state_page = new ProductStatePage();
            $this->product_state_page->register();

            $this->gundeals_performance_page = new GunDealsPerformancePage();
            $this->gundeals_performance_page->register();

            $this->upc_stock_alerts_page = new UpcStockAlertsPage($this->upc_stock_alert_cron_service);
            $this->upc_stock_alerts_page->register();

            $this->order_placement_metabox = new OrderPlacementMetaBox($this->distributor_handler->ordering_jobs_table);
            $this->order_placement_metabox->register();

            $this->authnet_order_rescue_button = new AuthorizeNetOrderRescueButton();
            $this->authnet_order_rescue_button->register();

            $this->order_cart_compliance_metabox = new OrderCartComplianceMetaBox();
            $this->order_cart_compliance_metabox->register();

            $this->order_profit_audit_metabox = new OrderProfitAuditMetaBox();
            $this->order_profit_audit_metabox->register();

            $this->order_fulfillment_mode_badge = new OrderFulfillmentModeBadge($this->distributor_handler->ordering_jobs_table);
            $this->order_fulfillment_mode_badge->register();

            $this->product_distributor_columns = new ProductDistributorColumns();
            $this->product_distributor_columns->register();

            $this->gundeals_click_columns = new GunDealsClickColumns();
            $this->gundeals_click_columns->register();

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
        CaliforniaRelayNotice::init();
        KlaviyoDefaultEmailOptIn::init();
        MailPoetDefaultOptIn::init();

    }

    /**
     * @return array<int,array<string,string>>
     */
    private function distributor_batch_queue_page_configs(): array
    {
        return [
            [
                'page_slug' => 'fflhub-lipseys-dealer-batch-queue',
                'menu_title' => "Lipsey's Dealer Batch Queue",
                'page_title' => "Lipsey's Dealer Batch Queue",
                'description' => "Per-line-item UPC queue view for Lipsey's dealer-fulfilled rows on Processing orders.",
                'dist_id' => 'lipseys',
                'dist_label' => "Lipsey's",
                'mode' => 'dealer',
                'mode_label' => 'Dealer Batch',
                'option_prefix' => 'fflhub_lipseys_dealer_batch',
                'field_prefix' => 'fflhub_lipseys_dealer_batch_page',
                'cron_hook' => LipseysDealerBatchCronService::CRON_HOOK,
            ],
            [
                'page_slug' => 'fflhub-zanders-dealer-batch-queue',
                'menu_title' => 'Zanders Dealer Batch Queue',
                'page_title' => 'Zanders Dealer Batch Queue',
                'description' => 'Per-line-item UPC queue view for Zanders dealer-fulfilled rows on Processing orders.',
                'dist_id' => 'zanders',
                'dist_label' => 'Zanders',
                'mode' => 'dealer',
                'mode_label' => 'Dealer Batch',
                'option_prefix' => 'fflhub_zanders_dealer_batch',
                'field_prefix' => 'fflhub_zanders_dealer_batch_page',
                'cron_hook' => ZandersDealerBatchCronService::CRON_HOOK,
                'manual_completion_enabled' => '1',
            ],
            [
                'page_slug' => 'fflhub-sports-south-dealer-batch-queue',
                'menu_title' => 'Sports South Dealer Batch Queue',
                'page_title' => 'Sports South Dealer Batch Queue',
                'description' => 'Per-line-item UPC queue view for Sports South dealer-fulfilled rows on Processing orders.',
                'dist_id' => 'sports_south',
                'dist_label' => 'Sports South',
                'mode' => 'dealer',
                'mode_label' => 'Dealer Batch',
                'option_prefix' => 'fflhub_sports_south_dealer_batch',
                'field_prefix' => 'fflhub_sports_south_dealer_batch_page',
                'cron_hook' => SportsSouthDealerBatchCronService::CRON_HOOK,
            ],
            [
                'page_slug' => 'fflhub-lipseys-ca-relay-batch-queue',
                'menu_title' => "Lipsey's CA Relay Batch Queue",
                'page_title' => "Lipsey's CA Relay Batch Queue",
                'description' => "Per-line-item UPC queue view for Lipsey's non-FFL CA relay rows on Processing orders.",
                'dist_id' => 'lipseys',
                'dist_label' => "Lipsey's",
                'mode' => 'ca_relay',
                'mode_label' => 'CA Relay Batch',
                'option_prefix' => 'fflhub_lipseys_ca_relay_batch',
                'field_prefix' => 'fflhub_lipseys_ca_relay_batch_page',
                'cron_hook' => LipseysCaRelayBatchCronService::CRON_HOOK,
            ],
            [
                'page_slug' => 'fflhub-zanders-ca-relay-batch-queue',
                'menu_title' => 'Zanders CA Relay Batch Queue',
                'page_title' => 'Zanders CA Relay Batch Queue',
                'description' => 'Per-line-item UPC queue view for Zanders non-FFL CA relay rows on Processing orders.',
                'dist_id' => 'zanders',
                'dist_label' => 'Zanders',
                'mode' => 'ca_relay',
                'mode_label' => 'CA Relay Batch',
                'option_prefix' => 'fflhub_zanders_ca_relay_batch',
                'field_prefix' => 'fflhub_zanders_ca_relay_batch_page',
                'cron_hook' => ZandersCaRelayBatchCronService::CRON_HOOK,
            ],
            [
                'page_slug' => 'fflhub-sports-south-ca-relay-batch-queue',
                'menu_title' => 'Sports South CA Relay Batch Queue',
                'page_title' => 'Sports South CA Relay Batch Queue',
                'description' => 'Per-line-item UPC queue view for Sports South non-FFL CA relay rows on Processing orders.',
                'dist_id' => 'sports_south',
                'dist_label' => 'Sports South',
                'mode' => 'ca_relay',
                'mode_label' => 'CA Relay Batch',
                'option_prefix' => 'fflhub_sports_south_ca_relay_batch',
                'field_prefix' => 'fflhub_sports_south_ca_relay_batch_page',
                'cron_hook' => SportsSouthCaRelayBatchCronService::CRON_HOOK,
            ],
        ];
    }

    public static function activate(): void
    {
        Options::init_defaults();
        DealerBatchOptimizerConfig::init_defaults();
        CategoryInstaller::install_default_categories();
        ProductStateStore::ensure_schema();
        DistributorOffersStore::ensure_schema();
        ProductBestOffersStore::ensure_schema();
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

        (new DealerBatchOptimizerAuditTable())->createTables();

        $upc_stock_alert_cron = new UpcStockAlertCronService();
        $upc_stock_alert_cron->on_activation();

        $mailpoet_auto_confirm_cron = new MailPoetAutoConfirmCronService();
        $mailpoet_auto_confirm_cron->on_activation();

        $gundeals_feed_cron = new GunDealsFeedCronService();
        $gundeals_feed_cron->on_activation();
        GunDealsAnalyticsStore::ensure_schema();
    }

    public static function deactivate(): void
    {
        $quote_email_jobs_cron = new QuoteEmailJobsCronService();
        $quote_email_jobs_cron->on_deactivation();

        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);

        $handler = new DistributorHandler($ffl_table);
        $handler->on_deactivate();

        $upc_stock_alert_cron = new UpcStockAlertCronService();
        $upc_stock_alert_cron->on_deactivation();

        $mailpoet_auto_confirm_cron = new MailPoetAutoConfirmCronService();
        $mailpoet_auto_confirm_cron->on_deactivation();

        $gundeals_feed_cron = new GunDealsFeedCronService();
        $gundeals_feed_cron->on_deactivation();
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
