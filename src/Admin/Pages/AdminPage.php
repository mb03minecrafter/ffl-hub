<?php

namespace FFLHub\Admin\Pages;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Settings\Options;
use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

/**
 * Renders the main FFL Hub admin page and loads its assets.
 */
class AdminPage
{
    /**
     * Slug of the settings page (used by the top-level FFL Hub menu).
     */
    private const PAGE_SLUG = 'ffl-hub-settings';
    private DistributorHandler $handler;

    public function __construct(DistributorHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Get the slug of the settings page so subpages can attach to it.
     */
    public static function get_page_slug(): string
    {
        return self::PAGE_SLUG;
    }

    /**
     * Initialize hooks for the main FFL Hub admin page.
     */
    public function register(): void
    {
        // Register the top-level FFL Hub menu.
        add_action('admin_menu', [$this, 'register_menu_page']);

        // Enqueue assets for the FFL Hub settings page.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Settings registration is owned by SettingsRegistrar.
        // This page handles UI rendering + admin actions only.

        // Handle enable/disable distributor actions.
        add_action('admin_post_fflhub_toggle_distributor', [$this, 'handle_toggle_distributor']);
    }


    /**
     * Enqueue CSS/JS only on our FFL Hub settings page.
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $base_url = FFLHUB_PLUGIN_URL . 'assets/';
        $css_path = FFLHUB_PLUGIN_PATH . 'assets/css/admin.css';
        $js_path  = FFLHUB_PLUGIN_PATH . 'assets/js/admin.js';
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : FFLHUB_PLUGIN_VERSION;
        $js_ver   = file_exists($js_path) ? (string) filemtime($js_path) : FFLHUB_PLUGIN_VERSION;

        wp_enqueue_style(
            'fflhub-admin',
            $base_url . 'css/admin.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'fflhub-admin',
            $base_url . 'js/admin.js',
            ['jquery'],
            $js_ver,
            true
        );
    }

    /**
     * Plugin's main page renderer, wired to the menu callback.
     */
    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        // IMPORTANT:
        // Admin UI is module-driven so distributors never "disappear" when disabled.
        $modules = DistributorRegistry::get_modules();

        self::render($modules);
    }

    /**
     * Register the top-level "FFL Hub" menu item.
     */
    public function register_menu_page(): void
    {
        add_menu_page(
            __('FFL Hub Settings', 'ffl-hub'),
            __('FFL Hub', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-admin-generic',
            56
        );
    }

    /**
     * Handle enable/disable distributor actions (from modal).
     */
    public function handle_toggle_distributor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'ffl-hub'));
        }

        $id = isset($_POST['distributor_id'])
            ? sanitize_text_field(wp_unslash($_POST['distributor_id']))
            : '';

        if ($id === '') {
            wp_die(esc_html__('Invalid distributor ID.', 'ffl-hub'));
        }

        check_admin_referer('fflhub_toggle_distributor_' . $id);

        $enable_flag = isset($_POST['enable'])
            ? sanitize_text_field(wp_unslash((string) $_POST['enable']))
            : '0';
        $enabled     = ($enable_flag === '1');

        // Centralize behavior in the handler so disabling halts cron/services.
        $this->handler->set_enabled($id, $enabled);

        $redirect = add_query_arg(
            [
                'page'               => self::PAGE_SLUG,
                'fflhub_toggle_done' => $id,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Entry point used internally once we have the modules.
     *
     * @param DistributorModuleInterface[] $modules
     */
    public static function render(array $modules): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $global_settings = [
            'payment_fee_percent'   => (string) Options::get_payment_processor_fee_percent(),
            'global_markup_percent' => (string) Options::get_global_markup(),
            'test_order_debug_enabled' => Options::get_test_order_debug_enabled() ? '1' : '0',
            'distributor_priority_list' => (string) Options::get_distributor_priority_csv(),

            'usps_estimate_enabled' => Options::get_usps_estimate_enabled() ? '1' : '0',
            'usps_use_test_env'     => Options::get_usps_use_test_env() ? '1' : '0',
            'usps_base_url'         => (string) Options::get_usps_base_url(),
            'usps_client_id'        => (string) Options::get_usps_client_id(),
            'usps_client_secret'    => (string) Options::get_usps_client_secret(),
            'usps_origin_zip'       => (string) Options::get_usps_origin_zip(),
            'usps_account_type'     => (string) Options::get_usps_account_type(),
            'usps_account_number'   => (string) Options::get_usps_account_number(),
            'usps_mail_class'       => (string) Options::get_usps_mail_class(),
            'usps_processing_category' => (string) Options::get_usps_processing_category(),
            'usps_destination_entry_facility_type' => (string) Options::get_usps_destination_entry_facility_type(),
            'usps_rate_indicator'   => (string) Options::get_usps_rate_indicator(),
            'usps_price_type'       => (string) Options::get_usps_price_type(),
            'usps_timeout_sec'      => (string) Options::get_usps_timeout_sec(),
            'usps_tare_weight_oz'   => (string) Options::get_usps_tare_weight_oz(),
        ];
?>
        <div class="wrap fflhub-wrap">
            <?php self::render_header(); ?>
            <?php self::render_global_settings_form($global_settings); ?>
            <?php self::render_usps_settings_shortcut($global_settings); ?>
            <?php self::render_distributor_grid($modules); ?>
            <?php self::render_modal($modules, $global_settings); ?>
        </div>
    <?php
    }

    /**
     * Renders the page heading / intro.
     */
    private static function render_header(): void
    {
    ?>
        <h1 class="fflhub-title"><?php esc_html_e('FFL Hub Settings', 'ffl-hub'); ?></h1>
        <p class="fflhub-description">
            <?php esc_html_e(
                'Select a distributor to configure its API credentials and options.',
                'ffl-hub'
            ); ?>
        </p>
    <?php
    }

    /**
     * Renders the global settings block (payment fee + markup).
     */
    /**
     * @param array<string,string> $settings
     */
    private static function render_global_settings_form(array $settings): void
    {
        $payment_fee_percent   = (string) ($settings['payment_fee_percent'] ?? '');
        $global_markup_percent = (string) ($settings['global_markup_percent'] ?? '');
        $test_order_debug_enabled = ((string) ($settings['test_order_debug_enabled'] ?? '0') === '1');
        $distributor_priority_list = (string) ($settings['distributor_priority_list'] ?? '');
        $priority_choices = [];
        foreach (DistributorRegistry::get_modules() as $module) {
            if (!($module instanceof DistributorModuleInterface)) {
                continue;
            }
            $priority_choices[] = $module->name() . ' (' . $module->id() . ')';
        }
        $priority_choices_text = implode(', ', $priority_choices);
        $priority_default_text = Options::default_distributor_priority_csv();

    ?>
        <form method="post" action="options.php" class="fflhub-global-settings-form">
            <?php settings_fields('fflhub_global_settings'); ?>

            <div class="fflhub-global-settings-card">
                <h2 class="fflhub-section-title">
                    <?php esc_html_e('Global Pricing Settings', 'ffl-hub'); ?>
                </h2>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_payment_processor_fee_percent"
                        class="fflhub-field-label">
                        <?php esc_html_e('Payment processor fee (%)', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_payment_processor_fee_percent"
                        name="fflhub_payment_processor_fee_percent"
                        type="number"
                        step="0.01"
                        min="0"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($payment_fee_percent); ?>" />
                    <span class="fflhub-field-suffix">%</span>
                    <p class="description">
                        <?php esc_html_e(
                            'Enter your payment processor fee as a percent (e.g. 2.9).',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_global_markup"
                        class="fflhub-field-label">
                        <?php esc_html_e('Global markup (%)', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_global_markup"
                        name="fflhub_global_markup"
                        type="number"
                        step="0.01"
                        min="0"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($global_markup_percent); ?>" />
                    <span class="fflhub-field-suffix">%</span>
                    <p class="description">
                        <?php esc_html_e(
                            'Default markup applied to your true cost when calculating prices.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_test_order_debug_enabled"
                        class="fflhub-field-label">
                        <?php esc_html_e('Test order debug mode', 'ffl-hub'); ?>
                    </label>
                    <input type="hidden" name="fflhub_test_order_debug_enabled" value="0" />
                    <input
                        id="fflhub_test_order_debug_enabled"
                        name="fflhub_test_order_debug_enabled"
                        type="checkbox"
                        value="1"
                        <?php checked($test_order_debug_enabled); ?> />
                    <p class="description">
                        <?php esc_html_e(
                            'When enabled, order jobs build distributor payloads but stop before outbound API calls. Place result stores the exact outbound message (JSON for Lipsey\'s/RSR, SOAP XML for Zanders).',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_distributor_priority_list"
                        class="fflhub-field-label">
                        <?php esc_html_e('Distributor tie-break priority', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_distributor_priority_list"
                        name="fflhub_distributor_priority_list"
                        type="text"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($distributor_priority_list); ?>"
                        placeholder="<?php echo esc_attr($priority_default_text); ?>" />
                    <p class="description">
                        <?php
                        echo esc_html(
                            sprintf(
                                __('Used only when true-costs tie. Enter distributor ids in priority order, separated by commas. Available: %s', 'ffl-hub'),
                                $priority_choices_text
                            )
                        );
                        ?>
                    </p>
                </div>

                <?php submit_button(__('Save Global Settings', 'ffl-hub')); ?>
            </div>
        </form>
    <?php
    }

    /**
     * USPS settings shortcut card that opens a dedicated modal panel.
     *
     * @param array<string,string> $settings
     */
    private static function render_usps_settings_shortcut(array $settings): void
    {
        $enabled = ((string) ($settings['usps_estimate_enabled'] ?? '0') === '1');
        $logo_url = FFLHUB_PLUGIN_URL . 'assets/icons/logo-usps.svg';
    ?>
        <div class="fflhub-usps-shortcut-wrap">
            <h2 class="fflhub-section-title"><?php esc_html_e('Shipping Integrations', 'ffl-hub'); ?></h2>
            <button
                type="button"
                class="fflhub-distributor-card fflhub-usps-card"
                data-fflhub-target="fflhub-panel-usps-settings">
                <div class="fflhub-distributor-card-icon fflhub-usps-card-icon">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="USPS logo" />
                </div>
                <div class="fflhub-distributor-card-text">
                    <span class="fflhub-distributor-name"><?php esc_html_e('USPS', 'ffl-hub'); ?></span>
                    <span class="fflhub-distributor-description">
                        <?php esc_html_e('Configure API credentials and outbound estimate behavior.', 'ffl-hub'); ?>
                    </span>
                </div>
                <div class="fflhub-distributor-status">
                    <?php if ($enabled) : ?>
                        <span class="fflhub-status-badge fflhub-status-enabled">
                            <?php esc_html_e('Enabled', 'ffl-hub'); ?>
                        </span>
                    <?php else : ?>
                        <span class="fflhub-status-badge fflhub-status-disabled">
                            <?php esc_html_e('Disabled', 'ffl-hub'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    <?php
    }

    /**
     * Renders the clickable distributor cards.
     * These just open the modal; toggling happens inside the modal.
     *
     * @param DistributorModuleInterface[] $modules
     */
    private static function render_distributor_grid(array $modules): void
    {
    ?>
        <div class="fflhub-distributor-grid">
            <?php foreach ($modules as $module) :
                if (! ($module instanceof DistributorModuleInterface)) {
                    continue;
                }

                $id          = $module->id();
                $name        = $module->name();
                $label       = $module->label();
                $description = $module->description();
                $icon_url    = $module->icon_url();

                $enabled = Options::is_distributor_enabled($id);
            ?>
                <button
                    type="button"
                    class="fflhub-distributor-card <?php echo $enabled ? 'enabled' : 'disabled'; ?>"
                    data-fflhub-target="fflhub-panel-<?php echo esc_attr($id); ?>">
                    <div class="fflhub-distributor-card-icon">
                        <?php if ($icon_url) : ?>
                            <img
                                src="<?php echo esc_url($icon_url); ?>"
                                alt="<?php echo esc_attr($name); ?> icon" />
                        <?php else : ?>
                            <span class="fflhub-distributor-label">
                                <?php echo esc_html($label ?: $name); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="fflhub-distributor-card-text">
                        <span class="fflhub-distributor-name">
                            <?php echo esc_html($name); ?>
                        </span>
                        <?php if ($description) : ?>
                            <span class="fflhub-distributor-description">
                                <?php echo esc_html($description); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="fflhub-distributor-status">
                        <?php if ($enabled) : ?>
                            <span class="fflhub-status-badge fflhub-status-enabled">
                                <?php esc_html_e('Enabled', 'ffl-hub'); ?>
                            </span>
                        <?php else : ?>
                            <span class="fflhub-status-badge fflhub-status-disabled">
                                <?php esc_html_e('Disabled', 'ffl-hub'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    <?php
    }

    /**
     * Renders modal panels for each distributor.
     *
     * @param DistributorModuleInterface[] $modules
     */
    private static function render_modal(array $modules, array $global_settings): void
    {
    ?>
        <div id="fflhub-modal" class="fflhub-modal" aria-hidden="true">
            <div class="fflhub-modal-overlay" data-fflhub-close="true"></div>

            <div class="fflhub-modal-dialog" role="dialog" aria-modal="true">
                <button
                    type="button"
                    class="fflhub-modal-close"
                    aria-label="<?php esc_attr_e('Close', 'ffl-hub'); ?>"
                    data-fflhub-close="true">
                    &times;
                </button>

                <div class="fflhub-modal-content">
                    <div
                        id="fflhub-panel-usps-settings"
                        class="fflhub-modal-panel"
                        aria-hidden="true">
                        <?php self::render_usps_settings_modal_panel($global_settings); ?>
                    </div>

                    <?php foreach ($modules as $module) :
                        if (! ($module instanceof DistributorModuleInterface)) {
                            continue;
                        }

                        $id = $module->id(); ?>
                        <div
                            id="fflhub-panel-<?php echo esc_attr($id); ?>"
                            class="fflhub-modal-panel"
                            aria-hidden="true">
                            <?php self::render_distributor_settings_form($module); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * @param array<string,string> $settings
     */
    private static function render_usps_settings_modal_panel(array $settings): void
    {
        $usps_estimate_enabled = (string) ($settings['usps_estimate_enabled'] ?? '0');
        $usps_use_test_env     = (string) ($settings['usps_use_test_env'] ?? '1');
        $usps_base_url         = (string) ($settings['usps_base_url'] ?? '');
        $usps_client_id        = (string) ($settings['usps_client_id'] ?? '');
        $usps_client_secret    = (string) ($settings['usps_client_secret'] ?? '');
        $usps_origin_zip       = (string) ($settings['usps_origin_zip'] ?? '');
        $usps_account_type     = (string) ($settings['usps_account_type'] ?? '');
        $usps_account_number   = (string) ($settings['usps_account_number'] ?? '');
        $usps_mail_class       = (string) ($settings['usps_mail_class'] ?? '');
        $usps_processing_category = (string) ($settings['usps_processing_category'] ?? '');
        $usps_destination_entry_facility_type = (string) ($settings['usps_destination_entry_facility_type'] ?? '');
        $usps_rate_indicator   = (string) ($settings['usps_rate_indicator'] ?? '');
        $usps_price_type       = (string) ($settings['usps_price_type'] ?? '');
        $usps_timeout_sec      = (string) ($settings['usps_timeout_sec'] ?? '');
        $usps_tare_weight_oz   = (string) ($settings['usps_tare_weight_oz'] ?? '0');
    ?>
        <div class="fflhub-distributor-settings-wrapper">
            <h2><?php esc_html_e('USPS Settings', 'ffl-hub'); ?></h2>
            <p class="description">
                <?php esc_html_e(
                    'These settings are used for dealer outbound shipping estimates during checkout. If USPS fails, the shipping method falls back to your formula pricing.',
                    'ffl-hub'
                ); ?>
            </p>

            <form method="post" action="options.php" class="fflhub-distributor-settings-form">
                <?php settings_fields(Options::usps_settings_group()); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_estimate_enabled"><?php esc_html_e('Enable USPS Outbound Estimates', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input type="hidden" name="fflhub_usps_estimate_enabled" value="0" />
                                <input
                                    id="fflhub_usps_estimate_enabled"
                                    name="fflhub_usps_estimate_enabled"
                                    type="checkbox"
                                    value="1"
                                    <?php checked($usps_estimate_enabled, '1'); ?> />
                                <p class="description"><?php esc_html_e('Turns USPS API pricing on for dealer->home and dealer->FFL LANES.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_use_test_env"><?php esc_html_e('Use Test Environment', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input type="hidden" name="fflhub_usps_use_test_env" value="0" />
                                <input
                                    id="fflhub_usps_use_test_env"
                                    name="fflhub_usps_use_test_env"
                                    type="checkbox"
                                    value="1"
                                    <?php checked($usps_use_test_env, '1'); ?> />
                                <p class="description"><?php esc_html_e('Checked uses apis-tem.usps.com. Unchecked uses production apis.usps.com.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_base_url"><?php esc_html_e('Base URL Override', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_base_url"
                                    name="fflhub_usps_base_url"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_base_url); ?>"
                                    placeholder="https://apis-tem.usps.com" />
                                <p class="description"><?php esc_html_e('Optional custom USPS API base URL. Leave blank to use the test/prod default.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_client_id"><?php esc_html_e('Client ID', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_client_id"
                                    name="fflhub_usps_client_id"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_client_id); ?>" />
                                <p class="description"><?php esc_html_e('USPS OAuth client ID from the USPS developer portal.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_client_secret"><?php esc_html_e('Client Secret', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_client_secret"
                                    name="fflhub_usps_client_secret"
                                    type="password"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_client_secret); ?>" />
                                <p class="description"><?php esc_html_e('USPS OAuth client secret. Stored in wp_options.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_origin_zip"><?php esc_html_e('Origin ZIP (Dealer)', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_origin_zip"
                                    name="fflhub_usps_origin_zip"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_origin_zip); ?>"
                                    placeholder="70801" />
                                <p class="description"><?php esc_html_e('Your shipping origin ZIP. Used as originZIPCode in quote requests.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_account_type"><?php esc_html_e('Account Type', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_account_type"
                                    name="fflhub_usps_account_type"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_account_type); ?>"
                                    placeholder="EPS" />
                                <p class="description"><?php esc_html_e('USPS account type value sent with quote requests when account number is present.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_account_number"><?php esc_html_e('Account Number', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_account_number"
                                    name="fflhub_usps_account_number"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_account_number); ?>" />
                                <p class="description"><?php esc_html_e('Optional USPS account number for negotiated/commercial quote context.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_mail_class"><?php esc_html_e('Mail Class', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_mail_class"
                                    name="fflhub_usps_mail_class"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_mail_class); ?>"
                                    placeholder="USPS_GROUND_ADVANTAGE" />
                                <p class="description"><?php esc_html_e('Service class to request from USPS, e.g. USPS_GROUND_ADVANTAGE.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_processing_category"><?php esc_html_e('Processing Category', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_processing_category"
                                    name="fflhub_usps_processing_category"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_processing_category); ?>"
                                    placeholder="MACHINABLE" />
                                <p class="description"><?php esc_html_e('Package handling category used by USPS rate logic.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_destination_entry_facility_type"><?php esc_html_e('Destination Facility Type', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_destination_entry_facility_type"
                                    name="fflhub_usps_destination_entry_facility_type"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_destination_entry_facility_type); ?>"
                                    placeholder="NONE" />
                                <p class="description"><?php esc_html_e('USPS destinationEntryFacilityType parameter (commonly NONE for estimates).', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_rate_indicator"><?php esc_html_e('Rate Indicator', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_rate_indicator"
                                    name="fflhub_usps_rate_indicator"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_rate_indicator); ?>"
                                    placeholder="<?php esc_attr_e('Optional', 'ffl-hub'); ?>" />
                                <p class="description"><?php esc_html_e('Optional USPS rateIndicator override for specific mail classes.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_price_type"><?php esc_html_e('Price Type', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_price_type"
                                    name="fflhub_usps_price_type"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_price_type); ?>"
                                    placeholder="COMMERCIAL" />
                                <p class="description"><?php esc_html_e('USPS priceType parameter, typically COMMERCIAL for merchant estimates.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_timeout_sec"><?php esc_html_e('Timeout (seconds)', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_timeout_sec"
                                    name="fflhub_usps_timeout_sec"
                                    type="number"
                                    min="3"
                                    step="1"
                                    class="small-text"
                                    value="<?php echo esc_attr($usps_timeout_sec); ?>" />
                                <p class="description"><?php esc_html_e('HTTP timeout for USPS token/rate requests.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_tare_weight_oz"><?php esc_html_e('Tare Weight (oz)', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_tare_weight_oz"
                                    name="fflhub_usps_tare_weight_oz"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="small-text"
                                    value="<?php echo esc_attr($usps_tare_weight_oz); ?>" />
                                <p class="description"><?php esc_html_e('Added to each USPS package quote weight to account for packaging materials.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save USPS Settings', 'ffl-hub')); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Render a full distributor settings form inside the modal,
     * including the Enable/Disable button.
     */
    private static function render_distributor_settings_form(DistributorModuleInterface $module): void
    {
        $id      = $module->id();
        $name    = $module->name();
        $fields  = $module->settings_schema();
        if (!is_array($fields)) {
            $fields = [];
        }
        $enabled = Options::is_distributor_enabled($id);

        $group = Options::distributor_settings_group($id);
        $credit_limit_option_name = Options::distributor_credit_limit_option_name($id);
        $credit_limit_default = (string) Options::default_distributor_credit_limit($id);
        $credit_limit_value = (string) Options::get_distributor_credit_limit($id, (float) $credit_limit_default);
        $non_dropship_blocked_option_name = Options::distributor_non_dropship_blocked_option_name($id);
        $non_dropship_blocked_enabled = Options::is_distributor_non_dropship_blocked($id);

    ?>
        <div class="fflhub-distributor-settings-wrapper">
            <h2>
                <?php echo esc_html($name); ?>
                <?php esc_html_e(' Settings', 'ffl-hub'); ?>
            </h2>

            <!-- Enable / Disable controls -->
            <div class="fflhub-distributor-toggle">
                <p>
                    <strong><?php esc_html_e('Current status:', 'ffl-hub'); ?></strong>
                    <?php if ($enabled) : ?>
                        <span class="fflhub-status-badge fflhub-status-enabled">
                            <?php esc_html_e('Enabled', 'ffl-hub'); ?>
                        </span>
                    <?php else : ?>
                        <span class="fflhub-status-badge fflhub-status-disabled">
                            <?php esc_html_e('Disabled', 'ffl-hub'); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('fflhub_toggle_distributor_' . $id); ?>
                    <input type="hidden" name="action" value="fflhub_toggle_distributor">
                    <input type="hidden" name="distributor_id" value="<?php echo esc_attr($id); ?>">
                    <input type="hidden" name="enable" value="<?php echo $enabled ? '0' : '1'; ?>">

                    <?php if ($enabled) : ?>
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Disable Distributor', 'ffl-hub'); ?>
                        </button>
                    <?php else : ?>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Enable Distributor', 'ffl-hub'); ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Distributor settings form -->
            <form method="post" action="options.php" class="fflhub-distributor-settings-form">
                <?php settings_fields($group); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($credit_limit_option_name); ?>">
                                    <?php esc_html_e('Credit Limit ($)', 'ffl-hub'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    id="<?php echo esc_attr($credit_limit_option_name); ?>"
                                    name="<?php echo esc_attr($credit_limit_option_name); ?>"
                                    value="<?php echo esc_attr((string) $credit_limit_value); ?>"
                                    class="regular-text" />
                                <p class="description">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            __('Used as the credit limit on the %s credit/status page.', 'ffl-hub'),
                                            $name
                                        )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($non_dropship_blocked_option_name); ?>">
                                    <?php esc_html_e('Block Non-Drop-Ship Items', 'ffl-hub'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="hidden" name="<?php echo esc_attr($non_dropship_blocked_option_name); ?>" value="0" />
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr($non_dropship_blocked_option_name); ?>"
                                    name="<?php echo esc_attr($non_dropship_blocked_option_name); ?>"
                                    value="1"
                                    <?php checked($non_dropship_blocked_enabled); ?> />
                                <p class="description">
                                    <?php esc_html_e('When enabled, this distributor is treated as drop-ship only. Non-drop-ship offers are ignored for product creation and product sync source selection.', 'ffl-hub'); ?>
                                </p>
                            </td>
                        </tr>
                        <?php foreach ($fields as $key => $field) :
                            $option_name = Options::distributor_option_name($id, $key);
                            $type        = isset($field['type']) ? strtolower((string) $field['type']) : 'text';
                            $label       = $field['label'] ?? $key;
                            $placeholder = $field['placeholder'] ?? '';
                            $desc        = $field['description'] ?? '';
                            $default     = isset($field['default']) ? (string) $field['default'] : '';
                            $value       = Options::get_distributor_option($id, $key, $default);
                            $options     = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];

                        ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($option_name); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </th>
                                <td>
                                    <?php if ($type === 'textarea') : ?>
                                        <textarea
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            placeholder="<?php echo esc_attr($placeholder); ?>"
                                            class="large-text"
                                            rows="4"><?php echo esc_textarea((string) $value); ?></textarea>
                                    <?php elseif ($type === 'select' && !empty($options)) : ?>
                                        <select
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            class="regular-text">
                                            <?php foreach ($options as $opt_key => $opt_label) :
                                                $option_value = is_string($opt_key) ? $opt_key : (string) $opt_label;
                                                $option_label = is_scalar($opt_label) ? (string) $opt_label : $option_value;
                                            ?>
                                                <option value="<?php echo esc_attr($option_value); ?>" <?php selected((string) $value, $option_value); ?>>
                                                    <?php echo esc_html($option_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'checkbox') : ?>
                                        <input type="hidden" name="<?php echo esc_attr($option_name); ?>" value="0" />
                                        <input
                                            type="checkbox"
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            value="1"
                                            <?php checked((string) $value, '1'); ?> />
                                    <?php else : ?>
                                        <?php
                                        $input_type = in_array($type, ['text', 'password', 'number', 'email', 'url'], true)
                                            ? $type
                                            : 'text';
                                        ?>
                                        <input
                                            type="<?php echo esc_attr($input_type); ?>"
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            value="<?php echo esc_attr((string) $value); ?>"
                                            placeholder="<?php echo esc_attr($placeholder); ?>"
                                            class="regular-text" />
                                    <?php endif; ?>
                                    <?php if ($desc) : ?>
                                        <p class="description"><?php echo esc_html($desc); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Save Settings', 'ffl-hub')); ?>
            </form>
        </div>
<?php
    }
}

