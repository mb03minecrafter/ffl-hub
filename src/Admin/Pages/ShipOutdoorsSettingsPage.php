<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\ShipOutdoors\ShipOutdoorsOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provider-specific settings screen for ShipOutdoors firearm labels.
 */
final class ShipOutdoorsSettingsPage
{
    private const PAGE_SLUG = ShippingAdminPage::SHIPOUTDOORS_SLUG;
    private const NONCE_ACTION = 'fflhub_shipoutdoors_settings';
    private const NONCE_FIELD = 'fflhub_shipoutdoors_settings_nonce';
    private const RESULT_TRANSIENT_PREFIX = 'fflhub_shipoutdoors_settings_result_';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('ShipOutdoors', 'ffl-hub'),
            __('ShipOutdoors', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();

        $this->maybe_handle_post();

        $settings = ShipOutdoorsOptions::get_all();
        $result = $this->read_result();
        ?>
        <div class="wrap fflhub-shipping-shipoutdoors">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('ShipOutdoors', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Production-only firearm UPS label provider for FFL-required dealer-fulfilled packages. Shared ship-from, package, label, and confirmation defaults live under the other FFLHub Shipping pages.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_shipoutdoors_settings_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('API', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enabled', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="shipoutdoors[enabled]" value="1" <?php checked(ShipOutdoorsOptions::is_enabled()); ?> />
                                        <?php esc_html_e('Enable ShipOutdoors as the firearm UPS provider for FFL Hub shipping labels.', 'ffl-hub'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('ShipOutdoors has no sandbox. Keep this disabled until you are ready to rate and buy production labels.', 'ffl-hub'); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php $this->render_api_key_row(); ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Notification Email', 'ffl-hub'); ?></th>
                                <td>
                                    <input
                                        type="email"
                                        name="shipoutdoors[notification_email]"
                                        class="regular-text"
                                        value="<?php echo esc_attr((string) ($settings['notification_email'] ?? '')); ?>"
                                        placeholder="<?php esc_attr_e('Optional UPS notification email', 'ffl-hub'); ?>"
                                    />
                                    <p class="description">
                                        <?php esc_html_e('Optional email ShipOutdoors sends to UPS for shipment updates when a label is purchased.', 'ffl-hub'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('Status', 'ffl-hub'); ?></h2>
                    <dl class="fflhub-shipping-grid">
                        <div class="fflhub-shipping-stat">
                            <?php esc_html_e('Provider', 'ffl-hub'); ?>
                            <strong><?php echo esc_html(ShipOutdoorsOptions::is_enabled() ? __('Enabled', 'ffl-hub') : __('Disabled', 'ffl-hub')); ?></strong>
                        </div>
                        <div class="fflhub-shipping-stat">
                            <?php esc_html_e('Production Key', 'ffl-hub'); ?>
                            <strong><?php echo esc_html(ShipOutdoorsOptions::api_key_mask() ?: __('Missing', 'ffl-hub')); ?></strong>
                            <p class="description"><?php echo esc_html(ShipOutdoorsOptions::api_key_source_label()); ?></p>
                        </div>
                    </dl>
                </section>

                <?php submit_button(__('Save ShipOutdoors Settings', 'ffl-hub'), 'primary', 'fflhub_shipoutdoors_save_settings'); ?>
            </form>

            <section class="fflhub-shipping-card">
                <h2><?php esc_html_e('Implementation Notes', 'ffl-hub'); ?></h2>
                <p>
                    <?php esc_html_e('ShipOutdoors is only used for FFL-required packages. EasyPost UPS and FedEx rates are hidden for those packages so firearm labels do not accidentally use a normal parcel account.', 'ffl-hub'); ?>
                </p>
            </section>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipoutdoors_settings_form'])) {
            return;
        }

        if (!current_user_can(ShippingAdminPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to save ShipOutdoors settings.', 'ffl-hub'));
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $input = isset($_POST['shipoutdoors']) && is_array($_POST['shipoutdoors'])
            ? wp_unslash($_POST['shipoutdoors'])
            : [];
        $input = is_array($input) ? $input : [];

        ShipOutdoorsOptions::save($input, isset($_POST['shipoutdoors_clear_production_api_key']));
        $this->store_result('success', 'ShipOutdoors settings saved.');

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function render_api_key_row(): void
    {
        $source = ShipOutdoorsOptions::api_key_source();
        $mask = ShipOutdoorsOptions::api_key_mask();
        $source_label = ShipOutdoorsOptions::api_key_source_label();
        $is_external = strpos($source, 'constant:') === 0 || strpos($source, 'environment:') === 0;
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Production API Key', 'ffl-hub'); ?></th>
            <td>
                <?php if ($is_external) : ?>
                    <p><strong><?php echo esc_html($mask); ?></strong></p>
                    <p class="description">
                        <?php echo esc_html(sprintf('Configured by %s. Saved option fallback is not shown or used while this exists.', $source_label)); ?>
                    </p>
                <?php else : ?>
                    <input
                        type="password"
                        name="shipoutdoors[production_api_key]"
                        class="regular-text"
                        value=""
                        autocomplete="new-password"
                        placeholder="<?php echo esc_attr($mask !== '' ? 'Leave blank to keep current production key' : 'Paste production API key'); ?>"
                    />
                    <p class="description">
                        <?php echo esc_html($mask !== '' ? 'Saved key: ' . $mask : 'No saved production API key.'); ?>
                    </p>
                    <?php if ($mask !== '') : ?>
                        <label><input type="checkbox" name="shipoutdoors_clear_production_api_key" value="1" /> <?php esc_html_e('Clear saved production key', 'ffl-hub'); ?></label>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function read_result(): ?array
    {
        $result = get_transient(self::RESULT_TRANSIENT_PREFIX . get_current_user_id());
        delete_transient(self::RESULT_TRANSIENT_PREFIX . get_current_user_id());
        return is_array($result) ? $result : null;
    }

    private function store_result(string $type, string $message): void
    {
        set_transient(self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), [
            'type' => $type,
            'message' => $message,
        ], 60);
    }

    /**
     * @param array{type:string,message:string}|null $result
     */
    private function render_result(?array $result): void
    {
        if (!is_array($result)) {
            return;
        }

        $class = ((string) ($result['type'] ?? '')) === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string) ($result['message'] ?? '')) . '</p></div>';
    }
}
