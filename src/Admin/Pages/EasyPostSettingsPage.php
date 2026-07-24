<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Placeholder for the future EasyPost provider page.
 *
 * Shared operational settings already live under the sibling Settings,
 * Package Presets, and Ship-From pages. This page should only grow EasyPost
 * credentials/account controls when that provider is implemented.
 */
final class EasyPostSettingsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('EasyPost', 'ffl-hub'),
            __('EasyPost', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::EASYPOST_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();
        ?>
        <div class="wrap fflhub-shipping-easypost">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('EasyPost', 'ffl-hub'); ?></h1>
            <section class="fflhub-shipping-card">
                <h2><?php esc_html_e('Provider Not Wired Yet', 'ffl-hub'); ?></h2>
                <p>
                    <?php esc_html_e('This page is reserved for EasyPost credentials, account controls, and provider-specific diagnostics. Shared shipping settings are already managed by the FFLHub Shipping pages.', 'ffl-hub'); ?>
                </p>
            </section>
        </div>
        <?php
    }
}
