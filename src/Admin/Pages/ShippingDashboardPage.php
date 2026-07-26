<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\ShippingOptions;
use FFLHub\Shipping\EasyPost\EasyPostOptions;
use FFLHub\Shipping\ShipStation\ShipStationOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Top-level landing page for provider-agnostic FFLHub shipping tools.
 */
final class ShippingDashboardPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_menu_page(
            __('FFLHub Shipping', 'ffl-hub'),
            __('FFLHub Shipping', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::DASHBOARD_SLUG,
            [$this, 'render_page'],
            'dashicons-airplane',
            57
        );

        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('Shipping Dashboard', 'ffl-hub'),
            __('Dashboard', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::DASHBOARD_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();

        $origin = ShippingOptions::origin_address();
        $origin_configured = trim((string) ($origin['address_line1'] ?? '')) !== ''
            && trim((string) ($origin['city_locality'] ?? '')) !== ''
            && trim((string) ($origin['state_province'] ?? '')) !== ''
            && trim((string) ($origin['postal_code'] ?? '')) !== '';
        ?>
        <div class="wrap fflhub-shipping-dashboard">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('FFLHub Shipping', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Shared shipping settings and provider configuration for admin label workflows.', 'ffl-hub'); ?>
            </p>

            <div class="fflhub-shipping-grid">
                <div class="fflhub-shipping-stat">
                    <?php esc_html_e('ShipStation', 'ffl-hub'); ?>
                    <strong><?php echo esc_html(ShipStationOptions::is_enabled() ? __('Enabled', 'ffl-hub') : __('Disabled', 'ffl-hub')); ?></strong>
                    <p class="description">
                        <?php echo esc_html(sprintf('Mode: %s. API key: %s.', ShipStationOptions::mode(), ShipStationOptions::active_api_key_source_label())); ?>
                    </p>
                </div>
                <div class="fflhub-shipping-stat">
                    <?php esc_html_e('EasyPost', 'ffl-hub'); ?>
                    <strong><?php echo esc_html(EasyPostOptions::is_enabled() ? __('Enabled', 'ffl-hub') : __('Disabled', 'ffl-hub')); ?></strong>
                    <p class="description">
                        <?php echo esc_html(sprintf('Mode: %s. API key: %s.', EasyPostOptions::mode(), EasyPostOptions::active_api_key_source_label())); ?>
                    </p>
                </div>
                <div class="fflhub-shipping-stat">
                    <?php esc_html_e('Ship-From Location', 'ffl-hub'); ?>
                    <strong><?php echo esc_html($origin_configured ? __('Configured', 'ffl-hub') : __('Missing', 'ffl-hub')); ?></strong>
                    <p class="description">
                        <?php echo esc_html(trim((string) ($origin['city_locality'] ?? '') . ', ' . (string) ($origin['state_province'] ?? ''), ' ,')); ?>
                    </p>
                </div>
                <div class="fflhub-shipping-stat">
                    <?php esc_html_e('Package Presets', 'ffl-hub'); ?>
                    <strong><?php echo esc_html((string) count(ShippingOptions::package_presets())); ?></strong>
                    <p class="description"><?php esc_html_e('Reusable boxes and envelopes for label buying.', 'ffl-hub'); ?></p>
                </div>
                <div class="fflhub-shipping-stat">
                    <?php esc_html_e('Banned Services', 'ffl-hub'); ?>
                    <strong><?php echo esc_html((string) count(ShippingOptions::banned_service_codes())); ?></strong>
                    <p class="description"><?php echo esc_html(implode(', ', ShippingOptions::banned_service_codes())); ?></p>
                </div>
            </div>

            <section class="fflhub-shipping-card">
                <h2><?php esc_html_e('Pages', 'ffl-hub'); ?></h2>
                <p>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::SETTINGS_SLUG)); ?>"><?php esc_html_e('Settings', 'ffl-hub'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKAGE_PRESETS_SLUG)); ?>"><?php esc_html_e('Package Presets', 'ffl-hub'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKAGE_AUDIT_SLUG)); ?>"><?php esc_html_e('Package Audit', 'ffl-hub'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKING_SLIPS_SLUG)); ?>"><?php esc_html_e('Packing Slips', 'ffl-hub'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::SHIP_FROM_SLUG)); ?>"><?php esc_html_e('Ship-From Locations', 'ffl-hub'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::SHIPSTATION_SLUG)); ?>"><?php esc_html_e('ShipStation API', 'ffl-hub'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::EASYPOST_SLUG)); ?>"><?php esc_html_e('EasyPost', 'ffl-hub'); ?></a>
                </p>
            </section>
        </div>
        <?php
    }
}
