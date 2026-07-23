<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Shipping\ShipStation\ShipStationCarrierCache;
use FFLHub\Shipping\ShipStation\ShipStationClient;
use FFLHub\Shipping\ShipStation\ShipStationOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FFL Hub settings screen for server-side ShipStation API v2 labels.
 */
final class ShipStationSettingsPage
{
    private const PAGE_SLUG = 'fflhub-shipstation-settings';
    private const NONCE_ACTION = 'fflhub_shipstation_settings';
    private const NONCE_FIELD = 'fflhub_shipstation_settings_nonce';
    private const RESULT_TRANSIENT_PREFIX = 'fflhub_shipstation_settings_result_';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('ShipStation Labels', 'ffl-hub'),
            __('ShipStation Labels', 'ffl-hub'),
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

        $settings = ShipStationOptions::get_all();
        $result = $this->read_result();
        $cache = ShipStationOptions::api_key_source() !== 'none' ? (new ShipStationCarrierCache())->get(false) : [];
        $carriers = !is_wp_error($cache) && is_array($cache['carriers'] ?? null) ? $cache['carriers'] : [];
        $cache_error = is_wp_error($cache) ? $cache->get_error_message() : (string) ($cache['error'] ?? '');
        $enabled_ids = ShipStationOptions::enabled_carrier_ids();
        $firearm_ids = ShipStationOptions::firearm_carrier_ids();
        ?>
        <div class="wrap fflhub-shipstation-settings">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('ShipStation Labels', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Server-side ShipStation API v2 labels for WooCommerce orders. The API key never goes to the browser.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>
            <?php if ($cache_error !== '') : ?>
                <div class="notice notice-warning"><p><?php echo esc_html($cache_error); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_shipstation_settings_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-ss-card">
                    <h2><?php esc_html_e('API', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enabled', 'ffl-hub'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="shipstation[enabled]" value="1" <?php checked(ShipStationOptions::is_enabled()); ?> />
                                        <?php esc_html_e('Enable FFL Hub ShipStation labels on WooCommerce orders.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Mode', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipstation[mode]">
                                        <option value="sandbox" <?php selected((string) $settings['mode'], 'sandbox'); ?>><?php esc_html_e('Sandbox', 'ffl-hub'); ?></option>
                                        <option value="production" <?php selected((string) $settings['mode'], 'production'); ?>><?php esc_html_e('Production', 'ffl-hub'); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Rates, carrier refreshes, and label purchases use the API key for the selected mode.', 'ffl-hub'); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php $this->render_api_key_row('sandbox', __('Sandbox API Key', 'ffl-hub')); ?>
                            <?php $this->render_api_key_row('production', __('Production API Key', 'ffl-hub')); ?>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-ss-card">
                    <h2><?php esc_html_e('Default Origin', 'ffl-hub'); ?></h2>
                    <?php $this->render_origin_fields($settings); ?>
                </section>

                <section class="fflhub-ss-card">
                    <h2><?php esc_html_e('Label Defaults', 'ffl-hub'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Format', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipstation[label_format]">
                                        <?php foreach (['pdf' => 'PDF', 'png' => 'PNG', 'zpl' => 'ZPL'] as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $settings['label_format'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Layout', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipstation[label_layout]">
                                        <option value="4x6" <?php selected((string) $settings['label_layout'], '4x6'); ?>>4x6</option>
                                        <option value="letter" <?php selected((string) $settings['label_layout'], 'letter'); ?>>Letter</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Confirmation', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipstation[confirmation]">
                                        <?php foreach (['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'] as $value) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $settings['confirmation'], $value); ?>><?php echo esc_html($value); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Insurance', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipstation[insurance_mode]">
                                        <option value="none" <?php selected((string) $settings['insurance_mode'], 'none'); ?>><?php esc_html_e('None', 'ffl-hub'); ?></option>
                                        <option value="declared_value" <?php selected((string) $settings['insurance_mode'], 'declared_value'); ?>><?php esc_html_e('Declare order value', 'ffl-hub'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Status After Purchase', 'ffl-hub'); ?></th>
                                <td>
                                    <select name="shipstation[after_purchase_status]">
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
                                        <input type="checkbox" name="shipstation[show_debug_fields]" value="1" <?php checked(ShipStationOptions::show_debug_fields()); ?> />
                                        <?php esc_html_e('Show carrier/service codes, delivery dates, and rate cost breakdowns in the order label panel.', 'ffl-hub'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="fflhub-ss-card">
                    <h2><?php esc_html_e('Package Presets', 'ffl-hub'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Define reusable boxes and envelopes for the order label panel. Weight is optional; order/product weight remains the default unless a preset is applied to an empty package row.', 'ffl-hub'); ?>
                    </p>
                    <?php $this->render_package_presets_table(ShipStationOptions::package_presets()); ?>
                </section>

                <section class="fflhub-ss-card">
                    <div class="fflhub-ss-card-head">
                        <div>
                            <h2><?php esc_html_e('Connected Carriers', 'ffl-hub'); ?></h2>
                            <p class="description">
                                <?php esc_html_e('Rates use all enabled carriers. FFL shipments use only firearm-approved carriers; if none are selected, USPS/Stamps.com is the temporary default.', 'ffl-hub'); ?>
                            </p>
                        </div>
                        <div>
                            <?php submit_button(__('Refresh / Test API Connection', 'ffl-hub'), 'secondary', 'fflhub_shipstation_refresh_carriers', false); ?>
                        </div>
                    </div>
                    <?php $this->render_carrier_table($carriers, $enabled_ids, $firearm_ids); ?>
                </section>

                <?php submit_button(__('Save ShipStation Settings', 'ffl-hub'), 'primary', 'fflhub_shipstation_save_settings'); ?>
            </form>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipstation_settings_form'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save ShipStation settings.', 'ffl-hub'));
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        $input = isset($_POST['shipstation']) && is_array($_POST['shipstation'])
            ? wp_unslash($_POST['shipstation'])
            : [];
        $input = is_array($input) ? $input : [];
        $clear_keys = [];
        if (isset($_POST['shipstation_clear_sandbox_api_key'])) {
            $clear_keys[] = 'sandbox';
        }
        if (isset($_POST['shipstation_clear_production_api_key'])) {
            $clear_keys[] = 'production';
        }
        ShipStationOptions::save($input, $clear_keys);

        if (isset($_POST['fflhub_shipstation_refresh_carriers'])) {
            $cache = (new ShipStationCarrierCache(new ShipStationClient()))->refresh();
            if (is_wp_error($cache)) {
                $this->store_result('error', 'ShipStation connection failed: ' . $cache->get_error_message());
            } else {
                $this->store_result('success', 'ShipStation connection succeeded. Carriers refreshed: ' . count((array) ($cache['carriers'] ?? [])) . '.');
            }
        } else {
            $this->store_result('success', 'ShipStation settings saved.');
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function render_api_key_row(string $mode, string $label): void
    {
        $field = $mode . '_api_key';
        $clear_field = 'shipstation_clear_' . $mode . '_api_key';
        $source = ShipStationOptions::api_key_source($mode);
        $mask = ShipStationOptions::api_key_mask($mode);
        $source_label = ShipStationOptions::api_key_source_label($source);
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
                        name="shipstation[<?php echo esc_attr($field); ?>]"
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
     * @param array<int,array<string,mixed>> $presets
     */
    private function render_package_presets_table(array $presets): void
    {
        echo '<table class="widefat striped fflhub-ss-package-presets">';
        echo '<thead><tr>';
        echo '<th>Name</th><th>Kind</th><th>ShipStation Code</th><th>Length</th><th>Width</th><th>Height</th><th>Default Weight oz</th><th>Remove</th>';
        echo '</tr></thead><tbody>';

        $rows = array_values($presets);
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [];
        }

        foreach ($rows as $index => $preset) {
            $preset = is_array($preset) ? $preset : [];
            $name = (string) ($preset['name'] ?? '');
            $id = (string) ($preset['id'] ?? '');
            $kind = (string) ($preset['kind'] ?? 'package');
            $code = (string) ($preset['package_code'] ?? 'package');
            $length = (string) ($preset['length'] ?? '');
            $width = (string) ($preset['width'] ?? '');
            $height = (string) ($preset['height'] ?? '');
            $weight_oz = (string) ($preset['weight_oz'] ?? '');

            echo '<tr>';
            echo '<td><input type="hidden" name="shipstation[package_presets][' . esc_attr((string) $index) . '][id]" value="' . esc_attr($id) . '" />';
            echo '<input class="regular-text" type="text" name="shipstation[package_presets][' . esc_attr((string) $index) . '][name]" value="' . esc_attr($name) . '" placeholder="Small Box" /></td>';
            echo '<td><select name="shipstation[package_presets][' . esc_attr((string) $index) . '][kind]">';
            foreach (['package' => 'Package', 'envelope' => 'Envelope'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($kind, $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td>';
            echo '<td><input type="text" name="shipstation[package_presets][' . esc_attr((string) $index) . '][package_code]" value="' . esc_attr($code) . '" placeholder="package" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipstation[package_presets][' . esc_attr((string) $index) . '][length]" value="' . esc_attr($length) . '" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipstation[package_presets][' . esc_attr((string) $index) . '][width]" value="' . esc_attr($width) . '" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipstation[package_presets][' . esc_attr((string) $index) . '][height]" value="' . esc_attr($height) . '" /></td>';
            echo '<td><input type="number" min="0" step="0.01" name="shipstation[package_presets][' . esc_attr((string) $index) . '][weight_oz]" value="' . esc_attr($weight_oz) . '" /></td>';
            echo '<td>';
            if ($name !== '' || $id !== '') {
                echo '<label><input type="checkbox" name="shipstation[package_presets][' . esc_attr((string) $index) . '][remove]" value="1" /> Remove</label>';
            } else {
                echo '<span class="description">New</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function render_origin_fields(array $settings): void
    {
        $fields = [
            'origin_name' => 'Contact Name',
            'origin_company' => 'Company',
            'origin_phone' => 'Phone',
            'origin_email' => 'Email',
            'origin_address1' => 'Address Line 1',
            'origin_address2' => 'Address Line 2',
            'origin_city' => 'City',
            'origin_state' => 'State',
            'origin_postal_code' => 'ZIP',
            'origin_country' => 'Country',
        ];
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($fields as $key => $label) {
            $type = $key === 'origin_email' ? 'email' : 'text';
            echo '<tr><th scope="row"><label for="fflhub-ss-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            echo '<input id="fflhub-ss-' . esc_attr($key) . '" class="regular-text" type="' . esc_attr($type) . '" name="shipstation[' . esc_attr($key) . ']" value="' . esc_attr((string) ($settings[$key] ?? '')) . '" />';
            echo '</td></tr>';
        }
        echo '<tr><th scope="row">Commercial / Residential</th><td><select name="shipstation[origin_residential]">';
        foreach (['no' => 'Commercial', 'yes' => 'Residential', 'unknown' => 'Unknown'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($settings['origin_residential'] ?? 'no'), $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
    }

    /**
     * @param array<int,array<string,mixed>> $carriers
     * @param string[] $enabled_ids
     * @param string[] $firearm_ids
     */
    private function render_carrier_table(array $carriers, array $enabled_ids, array $firearm_ids): void
    {
        if (empty($carriers)) {
            echo '<p class="description">No carrier cache found yet. Save settings and refresh/test the API connection.</p>';
            return;
        }

        echo '<table class="widefat striped fflhub-ss-carriers"><thead><tr>';
        echo '<th>Use</th><th>Firearm Approved</th><th>Nickname</th><th>Carrier</th><th>Status</th><th>Rates</th><th>Services</th>';
        echo '</tr></thead><tbody>';

        foreach ($carriers as $carrier) {
            if (!is_array($carrier)) {
                continue;
            }
            $id = (string) ($carrier['carrier_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $services = isset($carrier['services']) && is_array($carrier['services']) ? $carrier['services'] : [];
            echo '<tr>';
            echo '<td><input type="checkbox" name="shipstation[enabled_carrier_ids][]" value="' . esc_attr($id) . '" ' . checked(empty($enabled_ids) || in_array($id, $enabled_ids, true), true, false) . ' /></td>';
            echo '<td><input type="checkbox" name="shipstation[firearm_carrier_ids][]" value="' . esc_attr($id) . '" ' . checked(in_array($id, $firearm_ids, true), true, false) . ' /></td>';
            echo '<td><strong>' . esc_html((string) ($carrier['nickname'] ?? '')) . '</strong><br><code>' . esc_html($id) . '</code></td>';
            echo '<td>' . esc_html((string) ($carrier['friendly_name'] ?? $carrier['carrier_code'] ?? '')) . '<br><code>' . esc_html((string) ($carrier['carrier_code'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($carrier['connection_status'] ?? '')) . '</td>';
            echo '<td>' . (!empty($carrier['send_rates']) ? 'Yes' : 'No') . '</td>';
            echo '<td>' . esc_html((string) count($services)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-ss-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:18px 0;padding:18px}
            .fflhub-ss-card h2{margin-top:0}
            .fflhub-ss-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
            .fflhub-ss-carriers td,.fflhub-ss-carriers th{vertical-align:top}
            .fflhub-ss-package-presets input[type="number"]{width:86px}
            .fflhub-ss-package-presets input[type="text"]{width:100%;max-width:220px}
            .fflhub-ss-package-presets td,.fflhub-ss-package-presets th{vertical-align:middle}
        </style>
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
