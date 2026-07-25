<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\EasyPost\EasyPostClient;
use FFLHub\Shipping\EasyPost\EasyPostOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provider-specific settings screen for EasyPost labels.
 */
final class EasyPostSettingsPage
{
    private const PAGE_SLUG = ShippingAdminPage::EASYPOST_SLUG;
    private const NONCE_ACTION = 'fflhub_easypost_settings';
    private const NONCE_FIELD = 'fflhub_easypost_settings_nonce';
    private const RESULT_TRANSIENT_PREFIX = 'fflhub_easypost_settings_result_';

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
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();

        $this->maybe_handle_post();

        $settings = EasyPostOptions::get_all();
        $result = $this->read_result();
        ?>
        <div class="wrap fflhub-shipping-easypost">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('EasyPost', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Provider credentials for server-side EasyPost rating and labels. Ship-from address, packages, label defaults, and banned services are shared under the other FFLHub Shipping pages.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_easypost_settings_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('API', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enabled', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="easypost[enabled]" value="1" <?php checked(EasyPostOptions::is_enabled()); ?> />
                                        <?php esc_html_e('Enable EasyPost as an FFL Hub shipping provider.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Mode', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="easypost[mode]">
                                        <option value="test" <?php selected((string) $settings['mode'], 'test'); ?>><?php esc_html_e('Test', 'ffl-hub'); ?></option>
                                        <option value="production" <?php selected((string) $settings['mode'], 'production'); ?>><?php esc_html_e('Production', 'ffl-hub'); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('EasyPost separates free test activity from live production labels. The selected mode chooses which key is used.', 'ffl-hub'); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php $this->render_api_key_row('test', __('Test API Key', 'ffl-hub')); ?>
                            <?php $this->render_api_key_row('production', __('Production API Key', 'ffl-hub')); ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Address Validation', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="easypost[address_verification_mode]">
                                        <option value="off" <?php selected((string) $settings['address_verification_mode'], 'off'); ?>><?php esc_html_e('Off', 'ffl-hub'); ?></option>
                                        <option value="verify" <?php selected((string) $settings['address_verification_mode'], 'verify'); ?>><?php esc_html_e('Verify and return corrections', 'ffl-hub'); ?></option>
                                        <option value="strict" <?php selected((string) $settings['address_verification_mode'], 'strict'); ?>><?php esc_html_e('Strict verification errors', 'ffl-hub'); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Off omits EasyPost verification flags. Strict mode makes EasyPost reject unverified addresses.', 'ffl-hub'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-shipping-card">
                    <div class="fflhub-shipping-card-head">
                        <div>
                            <h2><?php esc_html_e('Connection Test', 'ffl-hub'); ?></h2>
                            <p class="description">
                                <?php esc_html_e('Tests the active API key against EasyPost carrier metadata. Connected account carrier-account lists are production-only in EasyPost, so this test is safe for test mode too.', 'ffl-hub'); ?>
                            </p>
                        </div>
                        <div>
                            <?php submit_button(__('Save and Test EasyPost', 'ffl-hub'), 'secondary', 'fflhub_easypost_test_connection', false); ?>
                        </div>
                    </div>
                    <dl class="fflhub-shipping-grid">
                        <div class="fflhub-shipping-stat">
                            <?php esc_html_e('Current Mode', 'ffl-hub'); ?>
                            <strong><?php echo esc_html(EasyPostOptions::mode()); ?></strong>
                        </div>
                        <div class="fflhub-shipping-stat">
                            <?php esc_html_e('Active Key', 'ffl-hub'); ?>
                            <strong><?php echo esc_html(EasyPostOptions::api_key_mask() ?: __('Missing', 'ffl-hub')); ?></strong>
                            <p class="description"><?php echo esc_html(EasyPostOptions::active_api_key_source_label()); ?></p>
                        </div>
                    </dl>
                </section>

                <?php submit_button(__('Save EasyPost Settings', 'ffl-hub'), 'primary', 'fflhub_easypost_save_settings'); ?>
            </form>

            <section class="fflhub-shipping-card">
                <h2><?php esc_html_e('Implementation Notes', 'ffl-hub'); ?></h2>
                <p>
                    <?php esc_html_e('EasyPost uses the API key as the Basic Auth username. We keep test and production keys separate, and shared package/origin defaults are intentionally not duplicated on this provider page.', 'ffl-hub'); ?>
                </p>
            </section>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_easypost_settings_form'])) {
            return;
        }

        if (!current_user_can(ShippingAdminPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to save EasyPost settings.', 'ffl-hub'));
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $input = isset($_POST['easypost']) && is_array($_POST['easypost'])
            ? wp_unslash($_POST['easypost'])
            : [];
        $input = is_array($input) ? $input : [];
        $clear_keys = [];
        if (isset($_POST['easypost_clear_test_api_key'])) {
            $clear_keys[] = 'test';
        }
        if (isset($_POST['easypost_clear_production_api_key'])) {
            $clear_keys[] = 'production';
        }

        EasyPostOptions::save($input, $clear_keys);

        if (isset($_POST['fflhub_easypost_test_connection'])) {
            $response = (new EasyPostClient())->carrier_metadata(['usps'], ['service_levels', 'predefined_packages']);
            if (is_wp_error($response)) {
                $this->store_result('error', 'EasyPost connection failed: ' . $response->get_error_message());
            } else {
                $carriers = isset($response['carriers']) && is_array($response['carriers']) ? $response['carriers'] : [];
                $this->store_result('success', 'EasyPost connection succeeded. Metadata carriers returned: ' . count($carriers) . '.');
            }
        } else {
            $this->store_result('success', 'EasyPost settings saved.');
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function render_api_key_row(string $mode, string $label): void
    {
        $field = $mode . '_api_key';
        $clear_field = 'easypost_clear_' . $mode . '_api_key';
        $source = EasyPostOptions::api_key_source($mode);
        $mask = EasyPostOptions::api_key_mask($mode);
        $source_label = EasyPostOptions::api_key_source_label($source);
        $is_external = strpos($source, 'constant:') === 0 || strpos($source, 'environment:') === 0;
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <?php if ($is_external) : ?>
                    <p><strong><?php echo esc_html($mask); ?></strong></p>
                    <p class="description">
                        <?php echo esc_html(sprintf('Configured by %s. Saved option fallback is not shown or used while this exists.', $source_label)); ?>
                    </p>
                <?php else : ?>
                    <input
                        type="password"
                        name="easypost[<?php echo esc_attr($field); ?>]"
                        class="regular-text"
                        value=""
                        autocomplete="new-password"
                        placeholder="<?php echo esc_attr($mask !== '' ? sprintf('Leave blank to keep current %s key', $mode) : sprintf('Paste %s API key', $mode)); ?>"
                    />
                    <p class="description">
                        <?php echo esc_html($mask !== '' ? 'Saved key: ' . $mask : 'No saved ' . $mode . ' API key.'); ?>
                    </p>
                    <?php if ($mask !== '') : ?>
                        <label><input type="checkbox" name="<?php echo esc_attr($clear_field); ?>" value="1" /> <?php echo esc_html(sprintf('Clear saved %s key', $mode)); ?></label>
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
