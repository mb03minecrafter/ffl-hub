<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Distributor\DistributorBase;
use FFLHub\Settings\Options;

/**
 * Renders the main FFL Hub admin page and loads its assets.
 */
class AdminPage
{
    /**
     * Slug of the settings page (used by the top-level FFL Hub menu).
     */
    private const PAGE_SLUG = 'ffl-hub-settings';

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
    public static function init(): void
    {
        // Register the top-level FFL Hub menu.
        add_action('admin_menu', [__CLASS__, 'register_menu_page']);

        // Enqueue assets for the FFL Hub settings page.
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Register global settings.
        add_action('admin_init', [__CLASS__, 'register_global_settings']);

        // Register distributor settings.
        add_action('admin_init', [__CLASS__, 'register_distributor_settings']);

        // Handle enable/disable distributor actions.
        add_action('admin_post_fflhub_toggle_distributor', [__CLASS__, 'handle_toggle_distributor']);
    }

    /**
     * Plugin's main page renderer, wired to the menu callback.
     */
    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        // Get distributors from the plugin singleton.
        $plugin   = Plugin::instance();
        $handler  = $plugin->distributor_handler ?? null;
        $distributors = $handler ? $handler->get_distributors() : [];

        self::render($distributors);
    }

    /**
     * Register the top-level "FFL Hub" menu item.
     */
    public static function register_menu_page(): void
    {
        add_menu_page(
            __('FFL Hub Settings', 'ffl-hub'),
            __('FFL Hub', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-admin-generic',
            56
        );
    }

    /**
     * Register global FFL Hub settings (non-distributor-specific),
     * including the payment processor percent fee and global markup.
     */
    public static function register_global_settings(): void
    {
        $settings_group = 'fflhub_global_settings';

        register_setting(
            $settings_group,
            'fflhub_payment_processor_fee_percent',
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_payment_processor_fee_percent'],
                'default'           => '2.9',
            ]
        );

        register_setting(
            $settings_group,
            'fflhub_global_markup',
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_payment_processor_fee_percent'],
                'default'           => '10.0',
            ]
        );
    }

    /**
     * Register settings for all distributors using their metadata.
     */
    public static function register_distributor_settings(): void
    {
        $handler = Plugin::instance()->distributor_handler ?? null;
        if (! $handler) {
            return;
        }

        $distributors = $handler->get_distributors();

        foreach ($distributors as $distributor) {
            $fields = $distributor->get_field_definitions();
            if (empty($fields)) {
                continue;
            }

            $group = 'fflhub_' . $distributor->get_id() . '_settings_group';

            foreach ($fields as $key => $field) {
                // We can't call protected methods from here, so replicate the option-name pattern:
                $option_name = 'fflhub_' . $distributor->get_id() . '_' . $key;
                register_setting($group, $option_name);
            }
        }
    }

    /**
     * Handle enable/disable distributor actions (from modal).
     */
    public static function handle_toggle_distributor(): void
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

        $enable_flag = isset($_POST['enable']) ? (string) $_POST['enable'] : '0';
        $enabled = ($enable_flag === '1');

        Options::set_distributor_enabled($id, $enabled);

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
     * Sanitize numeric percent fields (payment fee, markup, etc.).
     *
     * @param mixed $value Raw value from the form.
     * @return string
     */
    public static function sanitize_payment_processor_fee_percent($value): string
    {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);
        return (string) (float) $value;
    }

    /**
     * Enqueue CSS/JS only on our FFL Hub settings page.
     */
    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $base_url = FFLHUB_PLUGIN_URL . 'assets/';

        wp_enqueue_style(
            'fflhub-admin',
            $base_url . 'css/admin.css',
            [],
            FFLHUB_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'fflhub-admin',
            $base_url . 'js/admin.js',
            ['jquery'],
            FFLHUB_PLUGIN_VERSION,
            true
        );
    }

    /**
     * Entry point used internally once we have the distributors.
     */
    public static function render(array $distributors): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $payment_fee_percent   = get_option('fflhub_payment_processor_fee_percent', '2.9');
        $global_markup_percent = get_option('fflhub_global_markup', '10.0');
        ?>
        <div class="wrap fflhub-wrap">
            <?php self::render_header(); ?>
            <?php self::render_global_settings_form($payment_fee_percent, $global_markup_percent); ?>
            <?php self::render_distributor_grid($distributors); ?>
            <?php self::render_modal($distributors); ?>
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
    private static function render_global_settings_form(
        string $payment_fee_percent,
        string $global_markup_percent
    ): void {
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

                <?php submit_button(__('Save Global Settings', 'ffl-hub')); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Renders the clickable distributor cards.
     * These just open the modal; toggling happens inside the modal.
     */
    private static function render_distributor_grid(array $distributors): void
    {
        ?>
        <div class="fflhub-distributor-grid">
            <?php foreach ($distributors as $distributor) :
                $id          = $distributor->get_id();
                $name        = $distributor->get_name();
                $label       = $distributor->get_label();
                $description = $distributor->get_description();
                $icon_url    = $distributor->get_icon_url();

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
     */
    private static function render_modal(array $distributors): void
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
                    <?php foreach ($distributors as $distributor) :
                        $id = $distributor->get_id(); ?>
                        <div
                            id="fflhub-panel-<?php echo esc_attr($id); ?>"
                            class="fflhub-modal-panel"
                            aria-hidden="true">
                            <?php self::render_distributor_settings_form($distributor); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a full distributor settings form inside the modal,
     * including the Enable/Disable button.
     */
    private static function render_distributor_settings_form($distributor): void
    {
        $id     = $distributor->get_id();
        $name   = $distributor->get_name();
        $fields = $distributor->get_field_definitions();
        $enabled = Options::is_distributor_enabled($id);

        $group = 'fflhub_' . $id . '_settings_group';

        ?>
        <div class="fflhub-distributor-settings-wrapper">
            <h2>
                <?php echo esc_html($name); ?>
                <?php esc_html_e('Settings', 'ffl-hub'); ?>
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

                    <?php if ($enabled): ?>
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Disable Distributor', 'ffl-hub'); ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Enable Distributor', 'ffl-hub'); ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($fields)) : ?>
                <p><?php esc_html_e('No settings available for this distributor.', 'ffl-hub'); ?></p>
                <?php return; ?>
            <?php endif; ?>

            <!-- Distributor settings form -->
            <form method="post" action="options.php" class="fflhub-distributor-settings-form">
                <?php settings_fields($group); ?>

                <table class="form-table">
                    <tbody>
                        <?php foreach ($fields as $key => $field) :
                            $option_name = 'fflhub_' . $id . '_' . $key;
                            $type        = $field['type'] ?? 'text';
                            $label       = $field['label'] ?? $key;
                            $placeholder = $field['placeholder'] ?? '';
                            $desc        = $field['description'] ?? '';
                            $value       = get_option($option_name, $field['default'] ?? '');
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($option_name); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="<?php echo esc_attr($type); ?>"
                                        id="<?php echo esc_attr($option_name); ?>"
                                        name="<?php echo esc_attr($option_name); ?>"
                                        value="<?php echo esc_attr($value); ?>"
                                        placeholder="<?php echo esc_attr($placeholder); ?>"
                                        class="regular-text" />
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
