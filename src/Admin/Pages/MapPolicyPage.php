<?php

namespace FFLHub\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Settings\Options;

/**
 * Admin page for brand-level MAP policy assignment.
 */
final class MapPolicyPage
{
    private const PAGE_SLUG = 'fflhub-map-brand-policies';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('MAP Brand Policies', 'ffl-hub'),
            __('MAP Policies', 'ffl-hub'),
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

        $rows = Options::get_map_brand_policies();
        if (empty($rows)) {
            $rows[] = [
                'brand'  => '',
                'policy' => Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE,
            ];
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('MAP Brand Policies', 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e(
                    'Define how each listed brand should behave when a product is MAP-restricted.',
                    'ffl-hub'
                ); ?>
            </p>

            <?php if (isset($_GET['settings-updated']) && (string) $_GET['settings-updated'] === 'true') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('MAP brand policies saved.', 'ffl-hub'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(Options::map_policy_settings_group()); ?>

                <input type="hidden" name="<?php echo esc_attr(Options::OPTION_MAP_BRAND_POLICIES); ?>[__empty]" value="" />

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 45%;"><?php esc_html_e('Brand Name', 'ffl-hub'); ?></th>
                            <th style="width: 35%;"><?php esc_html_e('MAP Policy', 'ffl-hub'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Action', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fflhub-map-policy-rows">
                        <?php foreach ($rows as $index => $row) : ?>
                            <?php
                            $brand = is_array($row) ? (string) ($row['brand'] ?? '') : '';
                            $policy = is_array($row) ? (string) ($row['policy'] ?? '') : '';
                            $this->render_policy_row((int) $index, $brand, $policy);
                            ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 12px;">
                    <button type="button" class="button" id="fflhub-map-policy-add-row">
                        <?php esc_html_e('Add Brand', 'ffl-hub'); ?>
                    </button>
                </p>

                <?php submit_button(__('Save MAP Policies', 'ffl-hub')); ?>
            </form>
        </div>

        <script type="text/html" id="fflhub-map-policy-row-template">
            <?php $this->render_policy_row_template(); ?>
        </script>

        <script>
            (function () {
                var rowsEl = document.getElementById('fflhub-map-policy-rows');
                var addBtn = document.getElementById('fflhub-map-policy-add-row');
                var templateEl = document.getElementById('fflhub-map-policy-row-template');

                if (!rowsEl || !addBtn || !templateEl) {
                    return;
                }

                var nextIndex = rowsEl.querySelectorAll('tr').length;

                rowsEl.addEventListener('click', function (event) {
                    var target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }

                    if (target.classList.contains('fflhub-map-policy-remove')) {
                        var row = target.closest('tr');
                        if (row) {
                            row.remove();
                        }
                    }
                });

                addBtn.addEventListener('click', function () {
                    var html = templateEl.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                    nextIndex += 1;

                    var wrapper = document.createElement('tbody');
                    wrapper.innerHTML = html.trim();
                    var row = wrapper.firstElementChild;
                    if (!row) {
                        return;
                    }

                    rowsEl.appendChild(row);

                    var input = row.querySelector('input[type="text"]');
                    if (input) {
                        input.focus();
                    }
                });
            })();
        </script>
        <?php
    }

    private function render_policy_row_template(): void
    {
        $this->render_policy_row(
            '__INDEX__',
            '',
            Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE
        );
    }

    /**
     * @param string|int $index
     */
    private function render_policy_row($index, string $brand, string $policy): void
    {
        $option_name = Options::OPTION_MAP_BRAND_POLICIES;
        ?>
        <tr>
            <td>
                <input
                    type="text"
                    class="regular-text"
                    name="<?php echo esc_attr($option_name . '[' . $index . '][brand]'); ?>"
                    value="<?php echo esc_attr($brand); ?>"
                    placeholder="<?php esc_attr_e('e.g. SIG SAUER', 'ffl-hub'); ?>" />
            </td>
            <td>
                <select name="<?php echo esc_attr($option_name . '[' . $index . '][policy]'); ?>">
                    <?php $this->render_policy_options($policy); ?>
                </select>
            </td>
            <td>
                <button type="button" class="button-link-delete fflhub-map-policy-remove">
                    <?php esc_html_e('Remove', 'ffl-hub'); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    private function render_policy_options(string $selected): void
    {
        $selected = strtolower(trim($selected));
        $options = [
            Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE => __('Add to Cart for Price', 'ffl-hub'),
            Options::MAP_POLICY_EMAIL_FOR_QUOTE       => __('Email for Quote', 'ffl-hub'),
        ];

        foreach ($options as $value => $label) {
            ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($selected, $value); ?>>
                <?php echo esc_html($label); ?>
            </option>
            <?php
        }
    }
}
