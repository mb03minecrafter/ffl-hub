<?php

namespace FFLHub;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Admin\AdminMenuOrder;
use FFLHub\Admin\Orders\OrderPlacementMetaBox;
use FFLHub\Admin\Orders\AuthorizeNetOrderRescueButton;
use FFLHub\Admin\Orders\OrderBoxPackingMetaBox;
use FFLHub\Admin\Orders\OrderFulfillmentModeBadge;
use FFLHub\Admin\Orders\OrderCartComplianceMetaBox;
use FFLHub\Admin\Orders\OrderProfitAuditMetaBox;
use FFLHub\Admin\Orders\ShipStationOrderMetaBox;
use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\BillHicksEdiTestPage;
use FFLHub\Admin\Pages\CheckoutActivityPage;
use FFLHub\Admin\Pages\DavidsonsFailedJobsPage;
use FFLHub\Admin\Pages\DealerBatchOptimizerPage;
use FFLHub\Admin\Pages\DealerFulfilledJobsPage;
use FFLHub\Admin\Pages\DistributorBatchQueuePage;
use FFLHub\Admin\Pages\DistributorOrderingAdminPage;
use FFLHub\Admin\Pages\DistributorProductsPage;
use FFLHub\Admin\Pages\EasyPostSettingsPage;
use FFLHub\Admin\Pages\FastBoundIntegrationSettingsPage;
use FFLHub\Admin\Pages\FFLDocumentsRequiredPage;
use FFLHub\Admin\Pages\FailedPlaceOrderJobsPage;
use FFLHub\Admin\Pages\FFLImporterPage;
use FFLHub\Admin\Pages\LipseysCreditLimitPage;
use FFLHub\Admin\Pages\MapPolicyPage;
use FFLHub\Admin\Pages\ProductStateBulkPricingPage;
use FFLHub\Admin\Pages\ProductStatePage;
use FFLHub\Admin\Pages\PrintNodeSettingsPage;
use FFLHub\Admin\Pages\ReceivingLocalStockPage;
use FFLHub\Admin\Pages\ReceivingPage;
use FFLHub\Admin\Pages\ReceivingTestLabelsPage;
use FFLHub\Admin\Pages\SendingPage;
use FFLHub\Admin\Pages\SendingReadyPage;
use FFLHub\Admin\Pages\MonthlyProfitAuditPage;
use FFLHub\Admin\Products\ProductDistributorColumns;
use FFLHub\Admin\Pages\RSRBatchQueuePage;
use FFLHub\Admin\Pages\ShippingDashboardPage;
use FFLHub\Admin\Pages\ShippingPackageAuditPage;
use FFLHub\Admin\Pages\ShippingPackagePresetsPage;
use FFLHub\Admin\Pages\ShippingPackingSlipsPage;
use FFLHub\Admin\Pages\ShippingSettingsPage;
use FFLHub\Admin\Pages\ShippingShipFromLocationsPage;
use FFLHub\Admin\Pages\ShipOutdoorsSettingsPage;
use FFLHub\Admin\Pages\ShipStationSettingsPage;
use FFLHub\Admin\Pages\WMSAdminPage;
use FFLHub\Admin\Pages\ZandersCreditLimitPage;
use FFLHub\Admin\ProductMeta\BOMMetaBox;
use FFLHub\Admin\ProductMeta\OrderFFLPanel;
use FFLHub\Admin\ProductMeta\ProductMetaBox;
use FFLHub\Admin\WPCronWarning;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;
use FFLHub\Brand\ArchiveFaqBlock;
use FFLHub\Brand\BrandArchiveHeroBlock;
use FFLHub\Brand\CollectionCarouselBlock;
use FFLHub\Brand\ProductCollectionRewrite;
use FFLHub\Content\BlogPostCarouselBlock;
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
use FFLHub\Distributor\Services\Orders\Cron\BillHicksDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\CSSIDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\DavidsonsDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\LipseysCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\LipseysDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\SportsSouthCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\SportsSouthDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\ZandersCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\ZandersDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerAuditTable;
use FFLHub\Distributor\Services\Orders\Optimization\DealerBatchOptimizerConfig;
use FFLHub\Feeds\GunDeals\GunDealsFeedCronService;
use FFLHub\Feeds\GunMade\GunMadeFeedCronService;
use FFLHub\Feeds\GunMade\GunMadeFeedEndpoint;
use FFLHub\FFL\API\FFLApi;
use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Inventory\LocalStockUnitStore;
use FFLHub\Monitoring\SentryBrowserConfig;
use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Order\WooShippingLabelCostSync;
use FFLHub\Product\CategoryInstaller;
use FFLHub\Distributor\Services\OfferSync\ProductBestOffersStore;
use FFLHub\Product\MapPriceVisibility;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\Receiving\ReceivingSerialCorrectionsStore;
use FFLHub\Receiving\ReceivingTestShipmentStore;
use FFLHub\Settings\Options;
use FFLHub\Settings\SettingsRegistrar;
use FFLHub\Shipping\ShipStation\ShipStationRestController;
use FFLHub\Shipping\EasyPost\EasyPostRestController;
use FFLHub\Shipping\PrintNode\PrintNodePrintQueueStore;
use FFLHub\Shipping\Wordpress\ShippingRegistrar;
use FFLHub\Util\ActionSchedulerWebRunnerGuard;
use FFLHub\WMS\OrderWaverLabelCronService;
use FFLHub\WMS\OrderWaverPackingCronService;
use FFLHub\WMS\OrderWaverStore;
use FFLHub\Woo\Emails\FulfillmentEmailContent;

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
    public DistributorOrderingAdminPage $distributor_ordering_admin_page;
    public ReceivingPage $receiving_page;
    public ReceivingLocalStockPage $receiving_local_stock_page;
    public ReceivingTestLabelsPage $receiving_test_labels_page;
    public SendingPage $sending_page;
    public SendingReadyPage $sending_ready_page;
    public FastBoundIntegrationSettingsPage $fastbound_integration_settings_page;
    public FFLDocumentsRequiredPage $ffl_documents_required_page;
    public FailedPlaceOrderJobsPage $failed_place_order_jobs_page;
    public CheckoutActivityPage $checkout_activity_page;
    public BillHicksEdiTestPage $bill_hicks_edi_test_page;
    public DavidsonsFailedJobsPage $davidsons_failed_jobs_page;
    public RSRBatchQueuePage $rsr_batch_queue_page;
    /** @var DistributorBatchQueuePage[] */
    public array $distributor_batch_queue_pages = [];
    public ZandersCreditLimitPage $zanders_credit_limit_page;
    public LipseysCreditLimitPage $lipseys_credit_limit_page;
    public MapPolicyPage $map_policy_page;
    public ProductStatePage $product_state_page;
    public ProductStateBulkPricingPage $product_state_bulk_pricing_page;
    public MonthlyProfitAuditPage $monthly_profit_audit_page;
    public ShippingDashboardPage $shipping_dashboard_page;
    public ShippingSettingsPage $shipping_settings_page;
    public ShippingPackagePresetsPage $shipping_package_presets_page;
    public ShippingPackageAuditPage $shipping_package_audit_page;
    public ShippingPackingSlipsPage $shipping_packing_slips_page;
    public ShippingShipFromLocationsPage $shipping_ship_from_locations_page;
    public EasyPostSettingsPage $easypost_settings_page;
    public ShipOutdoorsSettingsPage $shipoutdoors_settings_page;
    public ShipStationSettingsPage $shipstation_settings_page;
    public PrintNodeSettingsPage $printnode_settings_page;
    public WMSAdminPage $wms_admin_page;
    public OrderPlacementMetaBox $order_placement_metabox;
    public AuthorizeNetOrderRescueButton $authnet_order_rescue_button;
    public OrderBoxPackingMetaBox $order_box_packing_metabox;
    public OrderCartComplianceMetaBox $order_cart_compliance_metabox;
    public OrderProfitAuditMetaBox $order_profit_audit_metabox;
    public ShipStationOrderMetaBox $shipstation_order_metabox;
    public OrderFulfillmentModeBadge $order_fulfillment_mode_badge;
    public ProductDistributorColumns $product_distributor_columns;

    // Frontend-only
    public CheckoutFields $checkout_fields;

    // Always-on
    public CartCompliance $cart_compliance;
    private QuoteEmailJobsCronService $quote_email_jobs_cron_service;
    private MailPoetAutoConfirmCronService $mailpoet_auto_confirm_cron_service;
    private GunDealsFeedCronService $gundeals_feed_cron_service;
    private GunMadeFeedCronService $gunmade_feed_cron_service;
    private OrderWaverPackingCronService $order_waver_packing_cron_service;
    private OrderWaverLabelCronService $order_waver_label_cron_service;

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
        SentryBrowserConfig::init();
        DealerBatchOptimizerConfig::init_defaults();
        ActionSchedulerWebRunnerGuard::init();
        ProductCollectionRewrite::init();
        BrandArchiveHeroBlock::init();
        ArchiveFaqBlock::init();
        CollectionCarouselBlock::init();
        BlogPostCarouselBlock::init();
        GunMadeFeedEndpoint::init();
        FulfillmentEmailContent::init();

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

        $this->mailpoet_auto_confirm_cron_service = new MailPoetAutoConfirmCronService();
        $this->mailpoet_auto_confirm_cron_service->register();

        $this->gundeals_feed_cron_service = new GunDealsFeedCronService();
        $this->gundeals_feed_cron_service->register();
        $this->gunmade_feed_cron_service = new GunMadeFeedCronService();
        $this->gunmade_feed_cron_service->register();
        self::cleanup_gundeals_analytics_tables_once();

        $this->order_waver_packing_cron_service = new OrderWaverPackingCronService();
        $this->order_waver_packing_cron_service->register();
        $this->order_waver_label_cron_service = new OrderWaverLabelCronService();
        $this->order_waver_label_cron_service->register();

        ShippingRegistrar::init();
        ShipStationRestController::init($this->ffl_table);
        EasyPostRestController::init();

        OrderProfitAuditMeta::init();
        WooShippingLabelCostSync::init();

        MapPriceVisibility::init();
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
            ReceivingEventsStore::ensure_schema();
            ReceivingSerialCorrectionsStore::ensure_schema();
            ReceivingTestShipmentStore::ensure_schema();
            LocalStockUnitStore::ensure_schema();
            OrderWaverStore::ensure_schema();
            PrintNodePrintQueueStore::ensure_schema();
            AdminMenuOrder::init();
            WPCronWarning::init();

            $this->admin_page = new AdminPage($this->distributor_handler);
            $this->admin_page->register();

            $this->distributor_ordering_admin_page = new DistributorOrderingAdminPage();
            $this->distributor_ordering_admin_page->register();

            $this->distributor_products_page = new DistributorProductsPage($this->distributor_handler);
            $this->distributor_products_page->register();

            $this->dealer_fulfilled_jobs_page = new DealerFulfilledJobsPage($this->distributor_handler->ordering_jobs_table);
            $this->dealer_fulfilled_jobs_page->register();

            $this->wms_admin_page = new WMSAdminPage();
            $this->wms_admin_page->register();

            $this->receiving_page = new ReceivingPage($this->distributor_handler->ordering_jobs_table);
            $this->receiving_page->register();

            $this->receiving_local_stock_page = new ReceivingLocalStockPage($this->distributor_handler->ordering_jobs_table);
            $this->receiving_local_stock_page->register();

            $this->sending_page = new SendingPage($this->distributor_handler->ordering_jobs_table);
            $this->sending_page->register();

            $this->sending_ready_page = new SendingReadyPage();
            $this->sending_ready_page->register();

            $this->receiving_test_labels_page = new ReceivingTestLabelsPage();
            $this->receiving_test_labels_page->register();

            $this->fastbound_integration_settings_page = new FastBoundIntegrationSettingsPage();
            $this->fastbound_integration_settings_page->register();

            $this->checkout_activity_page = new CheckoutActivityPage();
            $this->checkout_activity_page->register();

            $this->bill_hicks_edi_test_page = new BillHicksEdiTestPage($this->distributor_handler, $this->ffl_table);
            $this->bill_hicks_edi_test_page->register();

            $this->ffl_documents_required_page = new FFLDocumentsRequiredPage(
                $this->distributor_handler->ordering_jobs_table,
                $this->ffl_table
            );
            $this->ffl_documents_required_page->register();

            $this->failed_place_order_jobs_page = new FailedPlaceOrderJobsPage($this->distributor_handler->ordering_jobs_table);
            $this->failed_place_order_jobs_page->register();

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

            $this->product_state_bulk_pricing_page = new ProductStateBulkPricingPage();
            $this->product_state_bulk_pricing_page->register();

            $this->monthly_profit_audit_page = new MonthlyProfitAuditPage();
            $this->monthly_profit_audit_page->register();

            $this->shipping_dashboard_page = new ShippingDashboardPage();
            $this->shipping_dashboard_page->register();

            $this->shipping_settings_page = new ShippingSettingsPage();
            $this->shipping_settings_page->register();

            $this->shipping_package_presets_page = new ShippingPackagePresetsPage();
            $this->shipping_package_presets_page->register();

            $this->shipping_package_audit_page = new ShippingPackageAuditPage();
            $this->shipping_package_audit_page->register();

            $this->shipping_packing_slips_page = new ShippingPackingSlipsPage();
            $this->shipping_packing_slips_page->register();

            $this->shipping_ship_from_locations_page = new ShippingShipFromLocationsPage();
            $this->shipping_ship_from_locations_page->register();

            $this->easypost_settings_page = new EasyPostSettingsPage();
            $this->easypost_settings_page->register();

            $this->shipoutdoors_settings_page = new ShipOutdoorsSettingsPage();
            $this->shipoutdoors_settings_page->register();

            $this->shipstation_settings_page = new ShipStationSettingsPage();
            $this->shipstation_settings_page->register();

            $this->printnode_settings_page = new PrintNodeSettingsPage();
            $this->printnode_settings_page->register();

            $this->order_placement_metabox = new OrderPlacementMetaBox($this->distributor_handler->ordering_jobs_table);
            $this->order_placement_metabox->register();

            $this->authnet_order_rescue_button = new AuthorizeNetOrderRescueButton();
            $this->authnet_order_rescue_button->register();

            $this->order_box_packing_metabox = new OrderBoxPackingMetaBox();
            $this->order_box_packing_metabox->register();

            $this->order_cart_compliance_metabox = new OrderCartComplianceMetaBox();
            $this->order_cart_compliance_metabox->register();

            $this->order_profit_audit_metabox = new OrderProfitAuditMetaBox();
            $this->order_profit_audit_metabox->register();

            $this->shipstation_order_metabox = new ShipStationOrderMetaBox($this->ffl_table);
            $this->shipstation_order_metabox->register();

            $this->order_fulfillment_mode_badge = new OrderFulfillmentModeBadge($this->distributor_handler->ordering_jobs_table);
            $this->order_fulfillment_mode_badge->register();

            $this->product_distributor_columns = new ProductDistributorColumns();
            $this->product_distributor_columns->register();

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
                'page_slug' => 'fflhub-bill-hicks-dealer-batch-queue',
                'menu_title' => 'Bill Hicks Dealer Batch Queue',
                'page_title' => 'Bill Hicks Dealer Batch Queue',
                'description' => 'Per-line-item UPC queue view for Bill Hicks dealer-fulfilled rows on Processing orders.',
                'dist_id' => 'bill_hicks',
                'dist_label' => 'Bill Hicks',
                'mode' => 'dealer',
                'mode_label' => 'Dealer Batch',
                'option_prefix' => 'fflhub_bill_hicks_dealer_batch',
                'field_prefix' => 'fflhub_bill_hicks_dealer_batch_page',
                'cron_hook' => BillHicksDealerBatchCronService::CRON_HOOK,
            ],
            [
                'page_slug' => 'fflhub-cssi-dealer-batch-queue',
                'menu_title' => 'CSSI Dealer Batch Queue',
                'page_title' => 'CSSI Dealer Batch Queue',
                'description' => 'Per-line-item UPC queue view for CSSI dealer-fulfilled rows on Processing orders.',
                'dist_id' => 'cssi',
                'dist_label' => 'CSSI',
                'mode' => 'dealer',
                'mode_label' => 'Dealer Batch',
                'option_prefix' => 'fflhub_cssi_dealer_batch',
                'field_prefix' => 'fflhub_cssi_dealer_batch_page',
                'cron_hook' => CSSIDealerBatchCronService::CRON_HOOK,
            ],
            [
                'page_slug' => 'fflhub-davidsons-dealer-batch-queue',
                'menu_title' => "Davidson's Dealer Batch Queue",
                'page_title' => "Davidson's Dealer Batch Queue",
                'description' => "Per-line-item UPC queue view for Davidson's dealer-fulfilled rows on Processing orders. When the batch fires, FFLHub emails the manual order list and then moves rows to Davidson's Manual Order Status.",
                'dist_id' => 'davidsons',
                'dist_label' => "Davidson's",
                'mode' => 'dealer',
                'mode_label' => 'Dealer Batch',
                'option_prefix' => 'fflhub_davidsons_dealer_batch',
                'field_prefix' => 'fflhub_davidsons_dealer_batch_page',
                'cron_hook' => DavidsonsDealerBatchCronService::CRON_HOOK,
            ],
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
        ReceivingEventsStore::ensure_schema();
        ReceivingSerialCorrectionsStore::ensure_schema();
        OrderWaverStore::ensure_schema();
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

        $mailpoet_auto_confirm_cron = new MailPoetAutoConfirmCronService();
        $mailpoet_auto_confirm_cron->on_activation();

        $gundeals_feed_cron = new GunDealsFeedCronService();
        $gundeals_feed_cron->on_activation();
        $gunmade_feed_cron = new GunMadeFeedCronService();
        $gunmade_feed_cron->on_activation();
        $order_waver_packing_cron = new OrderWaverPackingCronService();
        $order_waver_packing_cron->on_activation();
        $order_waver_label_cron = new OrderWaverLabelCronService();
        $order_waver_label_cron->on_activation();
        self::cleanup_gundeals_analytics_tables_once(true);
    }

    public static function deactivate(): void
    {
        $quote_email_jobs_cron = new QuoteEmailJobsCronService();
        $quote_email_jobs_cron->on_deactivation();

        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);

        $handler = new DistributorHandler($ffl_table);
        $handler->on_deactivate();

        $mailpoet_auto_confirm_cron = new MailPoetAutoConfirmCronService();
        $mailpoet_auto_confirm_cron->on_deactivation();

        $gundeals_feed_cron = new GunDealsFeedCronService();
        $gundeals_feed_cron->on_deactivation();
        $gunmade_feed_cron = new GunMadeFeedCronService();
        $gunmade_feed_cron->on_deactivation();
        $order_waver_packing_cron = new OrderWaverPackingCronService();
        $order_waver_packing_cron->on_deactivation();
        $order_waver_label_cron = new OrderWaverLabelCronService();
        $order_waver_label_cron->on_deactivation();
    }

    private static function cleanup_gundeals_analytics_tables_once(bool $force = false): void
    {
        $option = 'fflhub_gundeals_analytics_removed_at';
        if (!$force && (string) get_option($option, '') !== '') {
            return;
        }

        if (!$force && !(is_admin() || (defined('WP_CLI') && WP_CLI))) {
            return;
        }

        global $wpdb;
        if (!$wpdb) {
            return;
        }

        foreach ([
            'fflhub_gundeals_click_events',
            'fflhub_gundeals_click_rollups',
            'fflhub_gundeals_click_totals',
            'fflhub_gundeals_feed_snapshots',
        ] as $suffix) {
            $table = self::sql_table_name($wpdb->prefix . $suffix);
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        foreach ([
            'fflhub_gundeals_analytics_schema_version',
            'fflhub_gundeals_legacy_click_meta_backfilled_at',
            'fflhub_gundeals_clicks_raw_total',
            'fflhub_gundeals_clicks_deduped_total',
            'fflhub_gundeals_clicks_raw_daily',
            'fflhub_gundeals_clicks_deduped_daily',
        ] as $old_option) {
            delete_option($old_option);
        }

        update_option($option, gmdate('Y-m-d H:i:s'), false);
    }

    private static function sql_table_name(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
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
