<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\PrintNode\PrintNodeClient;
use FFLHub\Shipping\PrintNode\PrintNodeOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PrintNode transport settings for warehouse printing.
 */
final class PrintNodeSettingsPage
{
    private const NONCE_ACTION = 'fflhub_printnode_settings';
    private const NONCE_FIELD = 'fflhub_printnode_settings_nonce';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('PrintNode', 'ffl-hub'),
            __('PrintNode', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::PRINTNODE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();
        $result = $this->maybe_handle_post();
        $settings = PrintNodeOptions::get_all();
        $printers = PrintNodeOptions::printers();
        ?>
        <div class="wrap fflhub-shipping-printnode">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('PrintNode', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('PrintNode sends finished label and packing-slip PDFs directly to the local printer connected through the PrintNode desktop client.', 'ffl-hub'); ?>
            </p>

            <?php if (is_array($result)) : ?>
                <div class="notice notice-<?php echo esc_attr((string) ($result['type'] ?? 'info')); ?> is-dismissible">
                    <p><?php echo esc_html((string) ($result['message'] ?? '')); ?></p>
                </div>
            <?php elseif (isset($_GET['settings_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('PrintNode settings saved.', 'ffl-hub'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_printnode_settings_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('Connection', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enabled', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="printnode[enabled]" value="1" <?php checked(PrintNodeOptions::is_enabled()); ?> />
                                        <?php esc_html_e('Enable PrintNode print buttons in FFL Hub WMS.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fflhub-printnode-api-key"><?php esc_html_e('API Key', 'ffl-hub'); ?></label></th>
                                <td>
                                    <input
                                        id="fflhub-printnode-api-key"
                                        class="regular-text"
                                        type="password"
                                        autocomplete="off"
                                        name="printnode[api_key]"
                                        value=""
                                        placeholder="<?php echo esc_attr(PrintNodeOptions::api_key_mask() ?: __('Paste API key', 'ffl-hub')); ?>" />
                                    <?php if (PrintNodeOptions::api_key_mask() !== '') : ?>
                                        <p class="description">
                                            <?php echo esc_html(sprintf('Current key: %s (%s).', PrintNodeOptions::api_key_mask(), PrintNodeOptions::api_key_source_label())); ?>
                                        </p>
                                        <label>
                                            <input type="checkbox" name="printnode[clear_api_key]" value="1" />
                                            <?php esc_html_e('Clear saved API key', 'ffl-hub'); ?>
                                        </label>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-shipping-card">
                    <div class="fflhub-shipping-card-head">
                        <div>
                            <h2><?php esc_html_e('Printer', 'ffl-hub'); ?></h2>
                            <p class="description">
                                <?php esc_html_e('Refresh after installing or signing into the PrintNode desktop client. The selected printer receives label/slip jobs from Order Waver.', 'ffl-hub'); ?>
                            </p>
                        </div>
                        <?php submit_button(__('Save and Refresh Printers', 'ffl-hub'), 'secondary', 'fflhub_printnode_refresh_printers', false); ?>
                    </div>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Default Printer', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="printnode[default_printer_id]">
                                        <option value=""><?php esc_html_e('Select a printer', 'ffl-hub'); ?></option>
                                        <?php foreach ($printers as $printer) : ?>
                                            <?php
                                            $label = trim(
                                                '#' . (string) $printer['id'] . ' - ' .
                                                (string) $printer['name'] .
                                                ((string) $printer['computer_name'] !== '' ? ' on ' . (string) $printer['computer_name'] : '') .
                                                ((string) $printer['state'] !== '' ? ' (' . (string) $printer['state'] . ')' : '')
                                            );
                                            ?>
                                            <option value="<?php echo esc_attr((string) $printer['id']); ?>" <?php selected((string) $settings['default_printer_id'], (string) $printer['id']); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($printers)) : ?>
                                        <p class="description"><?php esc_html_e('No cached printers yet. Save your API key, then click Save and Refresh Printers.', 'ffl-hub'); ?></p>
                                    <?php else : ?>
                                        <p class="description">
                                            <?php echo esc_html(sprintf('Cached printers: %d. Last refresh: %s UTC.', count($printers), PrintNodeOptions::printers_refreshed_at() ?: '-')); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-shipping-card">
                    <h2><?php esc_html_e('Print Job Defaults', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="fflhub-printnode-copies"><?php esc_html_e('Copies', 'ffl-hub'); ?></label></th>
                                <td>
                                    <input id="fflhub-printnode-copies" type="number" min="1" max="10" step="1" name="printnode[copies]" value="<?php echo esc_attr((string) $settings['copies']); ?>" />
                                    <p class="description"><?php esc_html_e('Applied to every submitted label and packing slip job.', 'ffl-hub'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fflhub-printnode-expire-after"><?php esc_html_e('Expire After Seconds', 'ffl-hub'); ?></label></th>
                                <td>
                                    <input id="fflhub-printnode-expire-after" type="number" min="60" max="604800" step="60" name="printnode[expire_after_seconds]" value="<?php echo esc_attr((string) $settings['expire_after_seconds']); ?>" />
                                    <p class="description"><?php esc_html_e('How long PrintNode should keep trying if the desktop client or printer is offline. Default is one day.', 'ffl-hub'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Fit To Page', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="printnode[fit_to_page]" value="1" <?php checked(PrintNodeOptions::fit_to_page()); ?> />
                                        <?php esc_html_e('Ask the printer driver to fit the PDF to the selected media.', 'ffl-hub'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('Leave this off for exact 4x6 thermal output unless your printer driver requires it.', 'ffl-hub'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <?php submit_button(__('Save PrintNode Settings', 'ffl-hub'), 'primary', 'fflhub_printnode_save_settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function maybe_handle_post(): ?array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_printnode_settings_form'])) {
            return null;
        }

        ShippingAdminPage::ensure_access();
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $input = isset($_POST['printnode']) && is_array($_POST['printnode'])
            ? wp_unslash($_POST['printnode'])
            : [];
        $input = is_array($input) ? $input : [];
        PrintNodeOptions::save($input, !empty($input['clear_api_key']));

        if (isset($_POST['fflhub_printnode_refresh_printers'])) {
            $printers = (new PrintNodeClient())->printers();
            if (is_wp_error($printers)) {
                return [
                    'type' => 'error',
                    'message' => $printers->get_error_message(),
                ];
            }

            PrintNodeOptions::save_printers($printers);
            return [
                'type' => 'success',
                'message' => sprintf(__('PrintNode settings saved. Refreshed %d printer(s).', 'ffl-hub'), count($printers)),
            ];
        }

        wp_safe_redirect(add_query_arg(['page' => ShippingAdminPage::PRINTNODE_SLUG, 'settings_saved' => '1'], admin_url('admin.php')));
        exit;
    }
}
