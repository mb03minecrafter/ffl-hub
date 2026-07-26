<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\Packing\PackingSlipService;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only preview surface for the HTML packing slips used by label workflows.
 */
final class ShippingPackingSlipsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('Packing Slips', 'ffl-hub'),
            __('Packing Slips', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::PACKING_SLIPS_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order = $order_id > 0 ? wc_get_order($order_id) : null;
        $order = $order instanceof WC_Order ? $order : null;
        $slip = (new PackingSlipService())->generate_preview($order);
        $is_order_preview = $order instanceof WC_Order;
        ?>
        <div class="wrap fflhub-shipping-packing-slips">
            <?php ShippingAdminPage::render_styles(); ?>
            <?php $this->render_inline_styles(); ?>

            <h1><?php esc_html_e('Packing Slips', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Preview the 4x6 print-ready packing slip template used by FFLHub label packages. This page is read-only and does not buy labels or modify orders.', 'ffl-hub'); ?>
            </p>

            <section class="fflhub-shipping-card">
                <div class="fflhub-shipping-card-head">
                    <div>
                        <h2><?php esc_html_e('Preview Input', 'ffl-hub'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Enter a Woo order ID to preview the first auto-packed dealer-fulfilled package, or leave it blank for the sample slip.', 'ffl-hub'); ?>
                        </p>
                    </div>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKAGE_PRESETS_SLUG)); ?>">
                        <?php esc_html_e('Edit Package Presets', 'ffl-hub'); ?>
                    </a>
                </div>

                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="fflhub-packing-slip-form">
                    <input type="hidden" name="page" value="<?php echo esc_attr(ShippingAdminPage::PACKING_SLIPS_SLUG); ?>" />
                    <label>
                        <span><?php esc_html_e('Woo Order ID', 'ffl-hub'); ?></span>
                        <input type="number" name="order_id" min="1" step="1" value="<?php echo esc_attr($order_id > 0 ? (string) $order_id : ''); ?>" />
                    </label>
                    <?php submit_button(__('Preview Slip', 'ffl-hub'), 'primary', 'submit', false); ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::PACKING_SLIPS_SLUG)); ?>">
                        <?php esc_html_e('Sample', 'ffl-hub'); ?>
                    </a>
                </form>

                <?php if ($order_id > 0 && !$is_order_preview) : ?>
                    <div class="notice notice-warning inline">
                        <p><?php echo esc_html(sprintf(__('Order #%d was not found, so the sample slip is shown.', 'ffl-hub'), $order_id)); ?></p>
                    </div>
                <?php elseif ($is_order_preview) : ?>
                    <div class="notice notice-info inline">
                        <p><?php echo esc_html(sprintf(__('Previewing order #%s. The preview uses the first package returned by the auto-packer.', 'ffl-hub'), $order->get_order_number())); ?></p>
                    </div>
                <?php else : ?>
                    <div class="notice notice-info inline">
                        <p><?php esc_html_e('Showing a sample packing slip because no order ID was entered.', 'ffl-hub'); ?></p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="fflhub-shipping-card">
                <div class="fflhub-shipping-card-head">
                    <div>
                        <h2><?php esc_html_e('Slip Preview', 'ffl-hub'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Use the print button inside the preview to check 4x6 browser print layout.', 'ffl-hub'); ?>
                        </p>
                    </div>
                </div>
                <iframe
                    class="fflhub-packing-slip-preview"
                    title="<?php esc_attr_e('Packing slip preview', 'ffl-hub'); ?>"
                    srcdoc="<?php echo esc_attr((string) ($slip['body'] ?? '')); ?>"
                ></iframe>
            </section>
        </div>
        <?php
    }

    private function render_inline_styles(): void
    {
        ?>
        <style>
            .fflhub-packing-slip-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-top:12px}
            .fflhub-packing-slip-form label{display:flex;flex-direction:column;gap:4px;font-weight:600}
            .fflhub-packing-slip-form label span{color:#50575e;font-size:12px}
            .fflhub-packing-slip-form input[type="number"]{width:180px}
            .fflhub-packing-slip-preview{display:block;width:100%;min-height:760px;border:1px solid #dcdcde;border-radius:6px;background:#f1f1f1}
        </style>
        <?php
    }
}
