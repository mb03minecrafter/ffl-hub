<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings for FastBound API credentials and distributor contacts.
 *
 * This page stores the data needed by future receiving/acquisition/disposition
 * flows. It deliberately does not call FastBound yet; API behavior belongs in
 * the receiving services once the credentials and contact mapping are known.
 */
final class FastBoundIntegrationSettingsPage
{
    private const PAGE_SLUG = 'fflhub-fastbound-integration-settings';
    private const NONCE_ACTION = 'fflhub_fastbound_integration_settings';
    private const NONCE_FIELD = 'fflhub_fastbound_integration_settings_nonce';
    private const FORM_ACTION = 'save_fastbound_integration_settings';
    private const EXTRA_BLANK_CONTACT_ROWS = 12;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('FastBound Integration Settings', 'ffl-hub'),
            __('FastBound Integration', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $this->maybe_handle_post();

        $contacts = Options::get_fastbound_distributor_contacts();
        $contact_rows = array_merge($contacts, array_fill(0, self::EXTRA_BLANK_CONTACT_ROWS, []));
        $distributor_options = $this->distributor_options();
        $api_key_configured = Options::get_fastbound_api_key() !== '';
        ?>
        <div class="wrap fflhub-fastbound-settings">
            <?php $this->render_styles(); ?>

            <h1><?php esc_html_e('FastBound Integration Settings', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Store the FastBound account credentials and map distributor locations to FastBound contacts. Receiving will use these contacts later for firearm acquisitions and dispositions.', 'ffl-hub'); ?>
            </p>

            <?php if (isset($_GET['fastbound_saved'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('FastBound settings saved.', 'ffl-hub'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_fastbound_action" value="<?php echo esc_attr(self::FORM_ACTION); ?>" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-fastbound-card">
                    <h2><?php esc_html_e('API Credentials', 'ffl-hub'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('FastBound API calls require the account number, API key, and an audit user email from your FastBound account.', 'ffl-hub'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable FastBound Integration', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(Options::OPTION_FASTBOUND_ENABLED); ?>"
                                            value="1"
                                            <?php checked(Options::get_fastbound_enabled()); ?>
                                        />
                                        <?php esc_html_e('Allow receiving/order flows to use FastBound once API code is wired in.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="fflhub-fastbound-account-number">
                                        <?php esc_html_e('Account Number', 'ffl-hub'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        id="fflhub-fastbound-account-number"
                                        class="regular-text"
                                        type="text"
                                        name="<?php echo esc_attr(Options::OPTION_FASTBOUND_ACCOUNT_NUMBER); ?>"
                                        value="<?php echo esc_attr(Options::get_fastbound_account_number()); ?>"
                                        autocomplete="off"
                                    />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="fflhub-fastbound-api-key">
                                        <?php esc_html_e('API Key', 'ffl-hub'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        id="fflhub-fastbound-api-key"
                                        class="regular-text"
                                        type="password"
                                        name="<?php echo esc_attr(Options::OPTION_FASTBOUND_API_KEY); ?>"
                                        value=""
                                        placeholder="<?php echo esc_attr($api_key_configured ? __('Leave blank to keep current key', 'ffl-hub') : __('Paste API key', 'ffl-hub')); ?>"
                                        autocomplete="new-password"
                                    />
                                    <p class="description">
                                        <?php echo esc_html($api_key_configured ? __('API key is currently stored. Blank keeps it unchanged.', 'ffl-hub') : __('No API key is currently stored.', 'ffl-hub')); ?>
                                    </p>
                                    <?php if ($api_key_configured) : ?>
                                        <label class="fflhub-fastbound-clear-key">
                                            <input type="checkbox" name="fflhub_fastbound_clear_api_key" value="1" />
                                            <?php esc_html_e('Clear stored API key', 'ffl-hub'); ?>
                                        </label>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="fflhub-fastbound-audit-user-email">
                                        <?php esc_html_e('Audit User Email', 'ffl-hub'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        id="fflhub-fastbound-audit-user-email"
                                        class="regular-text"
                                        type="email"
                                        name="<?php echo esc_attr(Options::OPTION_FASTBOUND_AUDIT_USER_EMAIL); ?>"
                                        value="<?php echo esc_attr(Options::get_fastbound_audit_user_email()); ?>"
                                        autocomplete="off"
                                    />
                                    <p class="description">
                                        <?php esc_html_e('FastBound requires this as X-AuditUser when writing acquisition/disposition records.', 'ffl-hub'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-fastbound-card">
                    <h2><?php esc_html_e('Distributor Contacts', 'ffl-hub'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Add one row per FastBound contact/location. Use multiple rows for distributors with more than one ship-from contact, such as Davidsons.', 'ffl-hub'); ?>
                    </p>

                    <div class="fflhub-fastbound-table-wrap">
                        <table class="widefat striped fflhub-fastbound-contact-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Enabled', 'ffl-hub'); ?></th>
                                    <th><?php esc_html_e('Distributor', 'ffl-hub'); ?></th>
                                    <th><?php esc_html_e('Label / Location', 'ffl-hub'); ?></th>
                                    <th><?php esc_html_e('FastBound Contact ID', 'ffl-hub'); ?></th>
                                    <th><?php esc_html_e('External ID', 'ffl-hub'); ?></th>
                                    <th><?php esc_html_e('FFL Number', 'ffl-hub'); ?></th>
                                    <th><?php esc_html_e('Notes', 'ffl-hub'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contact_rows as $index => $row) : ?>
                                    <?php $this->render_contact_row((int) $index, is_array($row) ? $row : [], $distributor_options); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php submit_button(__('Save FastBound Settings', 'ffl-hub')); ?>
            </form>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = isset($_POST['fflhub_fastbound_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_fastbound_action']))
            : '';
        if ($action !== self::FORM_ACTION) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'ffl-hub'));
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])), self::NONCE_ACTION)
        ) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        Options::set_fastbound_enabled(isset($_POST[Options::OPTION_FASTBOUND_ENABLED]));
        Options::set_fastbound_account_number($this->posted_text(Options::OPTION_FASTBOUND_ACCOUNT_NUMBER));
        Options::set_fastbound_audit_user_email(sanitize_email($this->posted_text(Options::OPTION_FASTBOUND_AUDIT_USER_EMAIL)));

        if (isset($_POST['fflhub_fastbound_clear_api_key'])) {
            Options::set_fastbound_api_key('');
        } else {
            $api_key = $this->posted_secret(Options::OPTION_FASTBOUND_API_KEY);
            if ($api_key !== '') {
                Options::set_fastbound_api_key($api_key);
            }
        }

        $contacts = [];
        if (isset($_POST[Options::OPTION_FASTBOUND_DISTRIBUTOR_CONTACTS]) && is_array($_POST[Options::OPTION_FASTBOUND_DISTRIBUTOR_CONTACTS])) {
            $contacts = wp_unslash($_POST[Options::OPTION_FASTBOUND_DISTRIBUTOR_CONTACTS]);
            $contacts = is_array($contacts) ? $contacts : [];
        }
        Options::set_fastbound_distributor_contacts($contacts);

        wp_safe_redirect(add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'fastbound_saved' => '1',
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $distributor_options
     */
    private function render_contact_row(int $index, array $row, array $distributor_options): void
    {
        $base = Options::OPTION_FASTBOUND_DISTRIBUTOR_CONTACTS . '[' . $index . ']';
        $enabled = array_key_exists('enabled', $row) ? !empty($row['enabled']) : true;
        $distributor_id = (string) ($row['distributor_id'] ?? '');
        ?>
        <tr>
            <td class="fflhub-fastbound-enabled-cell">
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($base . '[enabled]'); ?>"
                    value="1"
                    <?php checked($enabled); ?>
                />
            </td>
            <td>
                <select name="<?php echo esc_attr($base . '[distributor_id]'); ?>">
                    <option value=""><?php esc_html_e('Select...', 'ffl-hub'); ?></option>
                    <?php foreach ($distributor_options as $id => $label) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($distributor_id, $id); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input
                    type="text"
                    name="<?php echo esc_attr($base . '[label]'); ?>"
                    value="<?php echo esc_attr((string) ($row['label'] ?? '')); ?>"
                    placeholder="<?php echo esc_attr__('Prescott / NC / Main', 'ffl-hub'); ?>"
                />
            </td>
            <td>
                <input
                    type="text"
                    name="<?php echo esc_attr($base . '[fastbound_contact_id]'); ?>"
                    value="<?php echo esc_attr((string) ($row['fastbound_contact_id'] ?? '')); ?>"
                    autocomplete="off"
                />
            </td>
            <td>
                <input
                    type="text"
                    name="<?php echo esc_attr($base . '[fastbound_contact_external_id]'); ?>"
                    value="<?php echo esc_attr((string) ($row['fastbound_contact_external_id'] ?? '')); ?>"
                    autocomplete="off"
                />
            </td>
            <td>
                <input
                    type="text"
                    name="<?php echo esc_attr($base . '[ffl_number]'); ?>"
                    value="<?php echo esc_attr((string) ($row['ffl_number'] ?? '')); ?>"
                    autocomplete="off"
                />
            </td>
            <td>
                <textarea name="<?php echo esc_attr($base . '[notes]'); ?>" rows="2"><?php echo esc_textarea((string) ($row['notes'] ?? '')); ?></textarea>
            </td>
        </tr>
        <?php
    }

    /**
     * @return array<string,string>
     */
    private function distributor_options(): array
    {
        $options = [];

        foreach (DistributorRegistry::get_modules() as $module) {
            $id = $module->id();
            $label = $module->label();
            $name = $module->name();
            $options[$id] = $label === $name ? $label : $label . ' - ' . $name;
        }

        return $options;
    }

    private function posted_text(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash((string) $_POST[$key])));
    }

    private function posted_secret(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        $value = trim((string) wp_unslash($_POST[$key]));
        return (string) preg_replace('/[\r\n\t]+/', '', $value);
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-fastbound-settings .description {
                max-width: 980px;
            }

            .fflhub-fastbound-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                margin: 18px 0;
                padding: 18px 20px;
            }

            .fflhub-fastbound-card h2 {
                margin-top: 0;
            }

            .fflhub-fastbound-clear-key {
                display: inline-block;
                margin-top: 8px;
            }

            .fflhub-fastbound-table-wrap {
                overflow-x: auto;
            }

            .fflhub-fastbound-contact-table th,
            .fflhub-fastbound-contact-table td {
                vertical-align: top;
            }

            .fflhub-fastbound-contact-table select,
            .fflhub-fastbound-contact-table input[type="text"],
            .fflhub-fastbound-contact-table textarea {
                width: 100%;
                min-width: 130px;
            }

            .fflhub-fastbound-contact-table textarea {
                resize: vertical;
            }

            .fflhub-fastbound-enabled-cell {
                text-align: center;
                width: 70px;
            }
        </style>
        <?php
    }
}
