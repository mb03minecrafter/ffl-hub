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
use FFLHub\Util\DebugLogUtil;

/**
 * Main plugin bootstrapper for FFL Hub.
 */
final class Plugin
{
    /**
     * Toggle for init timing logs.
     * Define this somewhere early (e.g. wp-config.php) to enable:
     *   define('FFLHUB_DEBUG_BOOT', true);
     */
    private const DEBUG_CONST = 'FFLHUB_DEBUG_BOOT';

    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    public FFLSchema $ffl_table_schema;
    public FFLTable $ffl_table;

    public FFLApi $ffl_api;

    public DistributorHandler $distributor_handler;

    // Admin-only
    public FFLImporterPage $ffl_importer_page;
    public DistributorProductsPage $distributor_products_page;
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
        $prefix = '[Plugin]';

        $t0 = microtime(true);
        $t_last = $t0;
        $step_n = 0;

        $ctx_base = [
            'is_admin'    => is_admin(),
            'doing_ajax'  => (defined('DOING_AJAX') && DOING_AJAX),
            'doing_cron'  => (defined('DOING_CRON') && DOING_CRON),
            'wp_cli'      => (defined('WP_CLI')),
        ];

        $log_step = function (string $label, array $extra = []) use (
            &$t_last,
            $t0,
            &$step_n,
            $prefix,
            $ctx_base
        ): void {
            $now = microtime(true);
            $delta_ms = ($now - $t_last) * 1000.0;
            $since_ms = ($now - $t0) * 1000.0;
            $t_last = $now;
            $step_n++;

            DebugLogUtil::log_ctx(self::DEBUG_CONST, $prefix, 'BOOT STEP', array_merge($ctx_base, [
                'step' => $step_n,
                'label' => $label,
                'elapsed_ms' => round($delta_ms, 3),
                'since_start_ms' => round($since_ms, 3),
                'mem_kb' => (int) (memory_get_usage(true) / 1024),
            ], $extra));
        };

        $log_start = function () use ($prefix, $ctx_base): void {
            DebugLogUtil::log_ctx(self::DEBUG_CONST, $prefix, 'BOOT START', array_merge($ctx_base, [
                'mem_kb' => (int) (memory_get_usage(true) / 1024),
            ]));
        };

        $log_end = function (string $label) use ($prefix, $ctx_base, $t0): void {
            $now = microtime(true);
            $total_ms = ($now - $t0) * 1000.0;

            DebugLogUtil::log_ctx(self::DEBUG_CONST, $prefix, 'BOOT END', array_merge($ctx_base, [
                'label' => $label,
                'total_ms' => round($total_ms, 3),
                'mem_kb' => (int) (memory_get_usage(true) / 1024),
            ]));
        };

        $log_start();

        // -----------------------------------------------------------------
        // Always-on bootstrap
        // -----------------------------------------------------------------
        SettingsRegistrar::init();
        $log_step('SettingsRegistrar::init');

        $this->ffl_table_schema = new FFLSchema();
        $log_step('new FFLSchema');

        $this->ffl_table = new FFLTable($this->ffl_table_schema);
        $log_step('new FFLTable');

        $this->ffl_api = new FFLApi($this->ffl_table);
        $log_step('new FFLApi');

        $this->ffl_api->register();
        $log_step('FFLApi->register');

        $this->distributor_handler = new DistributorHandler($this->ffl_table);
        $log_step('new DistributorHandler');

        $this->distributor_handler->register_runtime_services();
        $log_step('DistributorHandler->register_runtime_services');

        ShippingRegistrar::init();
        $log_step('ShippingRegistrar::init');

        MapPriceVisibility::init();
        $log_step('MapPriceVisibility::init');

        $this->cart_compliance = new CartCompliance($this->ffl_table, $this->distributor_handler);
        $log_step('new CartCompliance');

        $this->cart_compliance->register();
        $log_step('CartCompliance->register');

        FFLRequiredCartExtension::init();
        $log_step('FFLRequiredCartExtension::init');

        // -----------------------------------------------------------------
        // Admin-only initialization
        // -----------------------------------------------------------------
        if (is_admin()) {
            WPCronWarning::init();
            $log_step('WPCronWarning::init');

            AdminPage::init();
            $log_step('AdminPage::init');

            $this->distributor_products_page = new DistributorProductsPage($this->distributor_handler);
            $log_step('new DistributorProductsPage');

            $this->distributor_products_page->register();
            $log_step('DistributorProductsPage->register');

            $this->order_placement_metabox = new OrderPlacementMetaBox($this->distributor_handler->ordering_jobs_table);
            $log_step('new OrderPlacementMetaBox', [
                'has_ordering_jobs_table' => isset($this->distributor_handler->ordering_jobs_table),
            ]);

            $this->order_placement_metabox->register();
            $log_step('OrderPlacementMetaBox->register');

            $this->ffl_importer_page = new FFLImporterPage($this->ffl_table);
            $log_step('new FFLImporterPage');

            $this->ffl_importer_page->register();
            $log_step('FFLImporterPage->register');

            OrderFFLPanel::init();
            $log_step('OrderFFLPanel::init');

            ProductMetaBox::init();
            $log_step('ProductMetaBox::init');

            $log_end('ADMIN');
            return;
        }

        // -----------------------------------------------------------------
        // Frontend-only initialization (checkout UX)
        // -----------------------------------------------------------------
        $this->checkout_fields = new CheckoutFields($this->ffl_table);
        $log_step('new CheckoutFields');

        $this->checkout_fields->register();
        $log_step('CheckoutFields->register');

        CheckoutMap::init();
        $log_step('CheckoutMap::init');

        $log_end('FRONTEND');
    }

    public static function activate(): void
    {
        Options::init_defaults();
        CategoryInstaller::install_default_categories();

        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);
        $ffl_table->createTables();

        $handler = new DistributorHandler($ffl_table);
        $handler->on_activate();
    }

    public static function deactivate(): void
    {
        $ffl_table_schema = new FFLSchema();
        $ffl_table        = new FFLTable($ffl_table_schema);
        $ffl_table->createTables();

        $handler = new DistributorHandler($ffl_table);
        $handler->on_deactivate();
    }
}
