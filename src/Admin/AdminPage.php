<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;

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
    }

    /**
     * Register the top-level "FFL Hub" menu item.
     */
    public static function register_menu_page(): void
    {
        add_menu_page(
            __('FFL Hub Settings', 'ffl-hub'), // Page title
            __('FFL Hub', 'ffl-hub'),          // Menu title
            'manage_options',
            self::PAGE_SLUG,
            [Plugin::instance(), 'render_settings_page'],
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
        // Settings group for our global options.
        $settings_group = 'fflhub_global_settings';

        register_setting(
            $settings_group,
            'fflhub_payment_processor_fee_percent',
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_payment_processor_fee_percent'],
                'default'           => '2.9', // Default to 2.9%
            ]
        );

        register_setting(
            $settings_group,
            'fflhub_global_markup',
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_payment_processor_fee_percent'],
                'default'           => '10.0', // Default to 10%
            ]
        );
    }

    /**
     * Sanitize numeric percent fields (payment fee, markup, etc.).
     *
     * @param mixed $value Raw value from the form.
     * @return string
     */
    public static function sanitize_payment_processor_fee_percent($value): string
    {
        // Allow only digits and a single dot.
        $value = preg_replace('/[^0-9.]/', '', (string) $value);

        // Cast to float then back to string for consistency.
        return (string) (float) $value;
    }

    /**
     * Enqueue CSS/JS only on our FFL Hub settings page.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets(string $hook): void
    {
        // Example hook: 'toplevel_page_ffl-hub-settings'
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        // Uses global plugin URL constant so file location doesn't matter.
        $base_url = FFLHUB_PLUGIN_URL . 'assets/';

        wp_enqueue_style(
            'fflhub-admin',
            $base_url . 'css/admin.css',
            [],
            '0.1.0'
        );

        wp_enqueue_script(
            'fflhub-admin',
            $base_url . 'js/admin.js',
            ['jquery'],
            '0.1.0',
            true
        );
    }

    /**
     * Entry point used by Plugin::render_settings_page().
     *
     * @param array $distributors Array of distributor objects (FFLHub_Distributor_Interface[]).
     */
    public static function render(array $distributors): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        // Fetch current values for global options.
        $payment_fee_percent   = get_option('fflhub_payment_processor_fee_percent', '2.9');
        $global_markup_percent = get_option('fflhub_global_markup', '10.0');
        ?>
        <div class="wrap fflhub-wrap">
            <?php self::render_header(); ?>

            <?php
            // Global settings form (payment processor fee + global markup).
            self::render_global_settings_form($payment_fee_percent, $global_markup_percent);
            ?>

            <?php self::render_distributor_grid($distributors); ?>

            <?php self::render_modal($distributors); ?>
        </div>
        <?php
    }

    /**
     * Renders the global settings block, including the payment processor fee field
     * and global markup percent.
     *
     * @param string $payment_fee_percent
     * @param string $global_markup_percent
     */
    private static function render_global_settings_form(string $payment_fee_percent, string $global_markup_percent): void
    {
        ?>
        <form
            method="post"
            action="options.php"
            class="fflhub-global-settings-form">

            <?php
            // Output nonce + hidden fields for our settings group.
            settings_fields('fflhub_global_settings');
            ?>

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
                            'Enter your payment processor fee as a percent (e.g. 2.9 for 2.9%).',
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
                            'Default markup applied to your true cost when calculating prices (e.g. 10 for 10%).',
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
     * Renders the clickable distributor cards (these trigger the modal).
     *
     * @param array $distributors
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
                ?>
                <button
                    type="button"
                    class="fflhub-distributor-card"
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
                </button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Renders a single modal container with one panel per distributor.
     *
     * @param array $distributors
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
                        $id = $distributor->get_id();
                        ?>
                        <div
                            id="fflhub-panel-<?php echo esc_attr($id); ?>"
                            class="fflhub-modal-panel"
                            aria-hidden="true">
                            <?php
                            // Distributor handles its own heading + form + fields.
                            $distributor->render_settings_panel();
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }
}


