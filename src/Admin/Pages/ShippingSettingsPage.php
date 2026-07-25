<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\ShippingOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared shipping behavior that applies across label providers.
 */
final class ShippingSettingsPage
{
    private const NONCE_ACTION = 'fflhub_shipping_settings';
    private const NONCE_FIELD = 'fflhub_shipping_settings_nonce';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('Shipping Settings', 'ffl-hub'),
            __('Settings', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::SETTINGS_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();
        $this->maybe_handle_post();

        $settings = ShippingOptions::get_all();
        ?>
        <div class="wrap fflhub-shipping-settings">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('Shipping Settings', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Shared defaults used by FFLHub label providers and order label panels.', 'ffl-hub'); ?>
            </p>

            <?php if (isset($_GET['settings_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Shipping settings saved.', 'ffl-hub'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_shipping_settings_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('Label Defaults', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Format', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipping[label_format]">
                                        <?php foreach (['pdf' => 'PDF', 'png' => 'PNG', 'zpl' => 'ZPL'] as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $settings['label_format'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Layout', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipping[label_layout]">
                                        <option value="4x6" <?php selected((string) $settings['label_layout'], '4x6'); ?>>4x6</option>
                                        <option value="letter" <?php selected((string) $settings['label_layout'], 'letter'); ?>>Letter</option>
                                    </select>
                                    <p class="description"><?php esc_html_e('ZPL labels are forced to 4x6.', 'ffl-hub'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Confirmation', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipping[confirmation]">
                                        <?php foreach (['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'] as $value) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $settings['confirmation'], $value); ?>><?php echo esc_html($value); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Insurance', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipping[insurance_mode]">
                                        <option value="none" <?php selected((string) $settings['insurance_mode'], 'none'); ?>><?php esc_html_e('None', 'ffl-hub'); ?></option>
                                        <option value="declared_value" <?php selected((string) $settings['insurance_mode'], 'declared_value'); ?>><?php esc_html_e('Declare order value', 'ffl-hub'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Status After Purchase', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipping[after_purchase_status]">
                                        <option value=""><?php esc_html_e('Do not change status', 'ffl-hub'); ?></option>
                                        <?php foreach (wc_get_order_statuses() as $status => $label) : ?>
                                            <?php $status_key = str_replace('wc-', '', (string) $status); ?>
                                            <option value="<?php echo esc_attr($status_key); ?>" <?php selected((string) $settings['after_purchase_status'], $status_key); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Show Debug Fields', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="shipping[show_debug_fields]" value="1" <?php checked(ShippingOptions::show_debug_fields()); ?> />
                                        <?php esc_html_e('Show carrier/service codes, delivery dates, rate cost breakdowns, and raw provider diagnostics in order label panels.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Exclude GlobalPost', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="shipping[exclude_globalpost]" value="1" <?php checked(ShippingOptions::exclude_globalpost()); ?> />
                                        <?php esc_html_e('Hide GlobalPost rates from admin label panels. Leave this enabled unless we intentionally start shipping internationally.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('Banned Shipping Methods', 'ffl-hub'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('One service code or service phrase per line. Matching rates are hidden before the admin can buy a label.', 'ffl-hub'); ?>
                    </p>
                    <textarea
                        class="large-text code fflhub-shipping-code-list"
                        rows="6"
                        name="shipping[banned_service_codes]"
                    ><?php echo esc_textarea(ShippingOptions::banned_service_codes_text()); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Default: usps_media_mail. Leaving this blank allows all returned services.', 'ffl-hub'); ?>
                    </p>
                </section>

                <?php submit_button(__('Save Shipping Settings', 'ffl-hub')); ?>
            </form>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipping_settings_form'])) {
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
            'label_format' => $input['label_format'] ?? 'pdf',
            'label_layout' => $input['label_layout'] ?? '4x6',
            'confirmation' => $input['confirmation'] ?? 'delivery',
            'insurance_mode' => $input['insurance_mode'] ?? 'none',
            'after_purchase_status' => $input['after_purchase_status'] ?? '',
            'show_debug_fields' => !empty($input['show_debug_fields']) ? '1' : '0',
            'exclude_globalpost' => !empty($input['exclude_globalpost']) ? '1' : '0',
            'banned_service_codes' => $input['banned_service_codes'] ?? '',
        ]);

        wp_safe_redirect(add_query_arg(['page' => ShippingAdminPage::SETTINGS_SLUG, 'settings_saved' => '1'], admin_url('admin.php')));
        exit;
    }
}
