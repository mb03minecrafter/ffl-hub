<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\ShippingOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared ship-from location settings.
 *
 * The current label workflow supports one default origin. This page isolates it
 * from provider credentials so future multi-location support can grow here.
 */
final class ShippingShipFromLocationsPage
{
    private const NONCE_ACTION = 'fflhub_shipping_ship_from';
    private const NONCE_FIELD = 'fflhub_shipping_ship_from_nonce';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('Shipping Ship-From Locations', 'ffl-hub'),
            __('Ship-From Locations', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::SHIP_FROM_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();
        $this->maybe_handle_post();
        $settings = ShippingOptions::get_all();
        ?>
        <div class="wrap fflhub-shipping-ship-from">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('Ship-From Locations', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Default origin used when FFLHub buys admin shipping labels.', 'ffl-hub'); ?>
            </p>

            <?php if (isset($_GET['origin_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Ship-from location saved.', 'ffl-hub'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_shipping_ship_from_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('Default Ship-From Location', 'ffl-hub'); ?></h2>
                    <?php $this->render_origin_fields($settings); ?>
                </section>

                <?php submit_button(__('Save Ship-From Location', 'ffl-hub')); ?>
            </form>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipping_ship_from_form'])) {
            return;
        }

        ShippingAdminPage::ensure_access();
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $input = isset($_POST['shipping']) && is_array($_POST['shipping'])
            ? wp_unslash($_POST['shipping'])
            : [];
        $input = is_array($input) ? $input : [];
        ShippingOptions::save_partial([
            'origin_name' => $input['origin_name'] ?? '',
            'origin_company' => $input['origin_company'] ?? '',
            'origin_phone' => $input['origin_phone'] ?? '',
            'origin_email' => $input['origin_email'] ?? '',
            'origin_address1' => $input['origin_address1'] ?? '',
            'origin_address2' => $input['origin_address2'] ?? '',
            'origin_city' => $input['origin_city'] ?? '',
            'origin_state' => $input['origin_state'] ?? '',
            'origin_postal_code' => $input['origin_postal_code'] ?? '',
            'origin_country' => $input['origin_country'] ?? 'US',
            'origin_residential' => $input['origin_residential'] ?? 'no',
        ]);

        wp_safe_redirect(add_query_arg(['page' => ShippingAdminPage::SHIP_FROM_SLUG, 'origin_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function render_origin_fields(array $settings): void
    {
        $fields = [
            'origin_name' => 'Contact Name',
            'origin_company' => 'Company',
            'origin_phone' => 'Phone',
            'origin_email' => 'Email',
            'origin_address1' => 'Address Line 1',
            'origin_address2' => 'Address Line 2',
            'origin_city' => 'City',
            'origin_state' => 'State',
            'origin_postal_code' => 'ZIP',
            'origin_country' => 'Country',
        ];

        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($fields as $key => $label) {
            $type = $key === 'origin_email' ? 'email' : 'text';
            echo '<tr><th scope="row"><label for="fflhub-shipping-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            echo '<input id="fflhub-shipping-' . esc_attr($key) . '" class="regular-text" type="' . esc_attr($type) . '" name="shipping[' . esc_attr($key) . ']" value="' . esc_attr((string) ($settings[$key] ?? '')) . '" />';
            echo '</td></tr>';
        }

        echo '<tr><th scope="row">Commercial / Residential</th><td><select name="shipping[origin_residential]">';
        foreach (['no' => 'Commercial', 'yes' => 'Residential', 'unknown' => 'Unknown'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($settings['origin_residential'] ?? 'no'), $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
    }
}
