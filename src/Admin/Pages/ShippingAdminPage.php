<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Constants and tiny shared UI helpers for the FFLHub Shipping admin area.
 */
final class ShippingAdminPage
{
    public const CAPABILITY = 'manage_woocommerce';
    public const MENU_SLUG = 'fflhub-shipping';
    public const DASHBOARD_SLUG = 'fflhub-shipping';
    public const SETTINGS_SLUG = 'fflhub-shipping-settings';
    public const PACKAGE_PRESETS_SLUG = 'fflhub-shipping-package-presets';
    public const PACKAGE_AUDIT_SLUG = 'fflhub-shipping-package-audit';
    public const SHIP_FROM_SLUG = 'fflhub-shipping-ship-from';
    public const EASYPOST_SLUG = 'fflhub-shipping-easypost';
    public const SHIPSTATION_SLUG = 'fflhub-shipping-shipstation';

    public static function ensure_access(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }
    }

    public static function render_styles(): void
    {
        ?>
        <style>
            .fflhub-shipping-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:18px 0;padding:18px}
            .fflhub-shipping-card h2{margin-top:0}
            .fflhub-shipping-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
            .fflhub-shipping-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:18px 0}
            .fflhub-shipping-stat{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px}
            .fflhub-shipping-stat strong{display:block;font-size:22px;line-height:1.2;margin-top:6px}
            .fflhub-shipping-package-presets input[type="number"]{width:86px}
            .fflhub-shipping-package-presets input[type="text"]{width:100%;max-width:220px}
            .fflhub-shipping-package-presets td,.fflhub-shipping-package-presets th{vertical-align:middle}
            .fflhub-shipping-carriers td,.fflhub-shipping-carriers th{vertical-align:top}
            .fflhub-shipping-code-list{font-family:Consolas,Monaco,monospace}
        </style>
        <?php
    }
}
