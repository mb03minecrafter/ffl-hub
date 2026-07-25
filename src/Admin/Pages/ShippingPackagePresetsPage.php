<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\ShippingOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared package preset editor for all shipping providers.
 */
final class ShippingPackagePresetsPage
{
    private const NONCE_ACTION = 'fflhub_shipping_package_presets';
    private const NONCE_FIELD = 'fflhub_shipping_package_presets_nonce';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            ShippingAdminPage::MENU_SLUG,
            __('Shipping Package Presets', 'ffl-hub'),
            __('Package Presets', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            ShippingAdminPage::PACKAGE_PRESETS_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();
        $this->maybe_handle_post();
        ?>
        <div class="wrap fflhub-shipping-package-presets-page">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('Package Presets', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Reusable boxes and envelopes for order label panels. Envelope length and width are flat usable dimensions; height is the maximum filled thickness.', 'ffl-hub'); ?>
            </p>

            <?php if (isset($_GET['presets_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Package presets saved.', 'ffl-hub'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_shipping_package_presets_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
                    <?php $this->render_package_presets_table(ShippingOptions::package_presets()); ?>
                </section>

                <?php submit_button(__('Save Package Presets', 'ffl-hub')); ?>
            </form>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipping_package_presets_form'])) {
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
            'package_presets' => $input['package_presets'] ?? [],
        ]);

        wp_safe_redirect(add_query_arg(['page' => ShippingAdminPage::PACKAGE_PRESETS_SLUG, 'presets_saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<int,array<string,mixed>> $presets
     */
    private function render_package_presets_table(array $presets): void
    {
        echo '<table class="widefat striped fflhub-shipping-package-presets">';
        echo '<thead><tr>';
        echo '<th>Type</th><th>Usable length</th><th>Usable width</th><th>Usable height</th><th>Default package weight</th><th>Remove</th>';
        echo '</tr></thead><tbody>';

        $rows = array_values($presets);
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [];
        }

        foreach ($rows as $index => $preset) {
            $preset = is_array($preset) ? $preset : [];
            $id = (string) ($preset['id'] ?? '');
            $kind = self::package_type($preset['kind'] ?? 'box');
            $length = (string) ($preset['length'] ?? '');
            $width = (string) ($preset['width'] ?? '');
            $height = (string) ($preset['height'] ?? '');
            $weight_oz = (string) ($preset['weight_oz'] ?? '');

            echo '<tr>';
            echo '<td><input type="hidden" name="shipping[package_presets][' . esc_attr((string) $index) . '][id]" value="' . esc_attr($id) . '" />';
            echo '<select name="shipping[package_presets][' . esc_attr((string) $index) . '][kind]">';
            foreach (['box' => 'Box', 'envelope' => 'Envelope'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($kind, $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipping[package_presets][' . esc_attr((string) $index) . '][length]" value="' . esc_attr($length) . '" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipping[package_presets][' . esc_attr((string) $index) . '][width]" value="' . esc_attr($width) . '" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipping[package_presets][' . esc_attr((string) $index) . '][height]" value="' . esc_attr($height) . '" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipping[package_presets][' . esc_attr((string) $index) . '][weight_oz]" value="' . esc_attr($weight_oz) . '" /></td>';
            echo '<td>';
            if ($id !== '') {
                echo '<label><input type="checkbox" name="shipping[package_presets][' . esc_attr((string) $index) . '][remove]" value="1" /> Remove</label>';
            } else {
                echo '<span class="description">New</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param mixed $value
     */
    private static function package_type($value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'package') {
            return 'box';
        }

        return in_array($value, ['box', 'envelope'], true) ? $value : 'box';
    }
}
