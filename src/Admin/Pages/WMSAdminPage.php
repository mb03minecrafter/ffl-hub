<?php

declare(strict_types=1);

namespace FFLHub\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Top-level admin home for warehouse management workflows.
 */
final class WMSAdminPage
{
    public const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'fflhub-wms';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_menu_page(
            __('FFLHub WMS', 'ffl-hub'),
            __('FFLHub WMS', 'ffl-hub'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-archive',
            59
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('WMS Dashboard', 'ffl-hub'),
            __('Dashboard', 'ffl-hub'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public static function ensure_access(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }
    }

    public function render_page(): void
    {
        self::ensure_access();
        ?>
        <div class="wrap fflhub-wms">
            <?php self::render_styles(); ?>
            <h1><?php esc_html_e('FFLHub WMS', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Warehouse receiving, Order Waver, and scanner test-label tools.', 'ffl-hub'); ?>
            </p>

            <div class="fflhub-wms-grid">
                <a class="fflhub-wms-card" href="<?php echo esc_url(admin_url('admin.php?page=fflhub-receiving')); ?>">
                    <strong><?php esc_html_e('Receiving', 'ffl-hub'); ?></strong>
                    <span><?php esc_html_e('Scan inbound distributor shipments, receive items, and acquire serialized firearms into FastBound.', 'ffl-hub'); ?></span>
                </a>
                <a class="fflhub-wms-card" href="<?php echo esc_url(admin_url('admin.php?page=fflhub-sending')); ?>">
                    <strong><?php esc_html_e('Order Waver', 'ffl-hub'); ?></strong>
                    <span><?php esc_html_e('Review orders whose dealer-fulfilled items are received and ready for packing or outbound labels.', 'ffl-hub'); ?></span>
                </a>
                <a class="fflhub-wms-card" href="<?php echo esc_url(admin_url('admin.php?page=fflhub-receiving-test-labels')); ?>">
                    <strong><?php esc_html_e('Receiving Test Labels', 'ffl-hub'); ?></strong>
                    <span><?php esc_html_e('Print debug barcode labels for testing the receiving scanner workflow.', 'ffl-hub'); ?></span>
                </a>
            </div>
        </div>
        <?php
    }

    public static function render_styles(): void
    {
        ?>
        <style>
            .fflhub-wms{max-width:1100px}
            .fflhub-wms-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:18px}
            .fflhub-wms-card{display:block;text-decoration:none;background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:6px;padding:16px;color:#1d2327}
            .fflhub-wms-card:hover{border-color:#2271b1;background:#f6f7f7}
            .fflhub-wms-card strong{display:block;font-size:16px;color:#135e96}
            .fflhub-wms-card span{display:block;margin-top:6px;color:#646970}
        </style>
        <?php
    }
}
