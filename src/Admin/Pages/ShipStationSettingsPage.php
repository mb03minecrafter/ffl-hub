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
 * Provider-specific settings screen for ShipStation API v2 labels.
 */
final class ShipStationSettingsPage
{
    private const PAGE_SLUG = ShippingAdminPage::SHIPSTATION_SLUG;
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
            ShippingAdminPage::MENU_SLUG,
            __('ShipStation API', 'ffl-hub'),
            __('ShipStation API', 'ffl-hub'),
            ShippingAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        ShippingAdminPage::ensure_access();

        $this->maybe_handle_post();

        $settings = ShipStationOptions::get_all();
        $result = $this->read_result();
        $cache = ShipStationOptions::api_key_source() !== 'none' ? (new ShipStationCarrierCache())->get(false) : [];
        $carriers = !is_wp_error($cache) && is_array($cache['carriers'] ?? null) ? $cache['carriers'] : [];
        $cache_error = is_wp_error($cache) ? $cache->get_error_message() : (string) ($cache['error'] ?? '');
        $enabled_ids = $this->selection_for_current_cache(ShipStationOptions::enabled_carrier_ids(), $carriers);
        $firearm_ids = $this->selection_for_current_cache(ShipStationOptions::firearm_carrier_ids(), $carriers);
        ?>
        <div class="wrap fflhub-shipstation-settings">
            <?php ShippingAdminPage::render_styles(); ?>
            <h1><?php esc_html_e('ShipStation API', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Provider credentials and carrier eligibility for server-side ShipStation API v2 labels. Shared shipping defaults live under the other FFLHub Shipping pages.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>
            <?php if ($cache_error !== '') : ?>
                <div class="notice notice-warning"><p><?php echo esc_html($cache_error); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_shipstation_settings_form" value="1" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="fflhub-shipping-card">
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

                <section class="fflhub-shipping-card">
                    <div class="fflhub-shipping-card-head">
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

                <?php submit_button(__('Save ShipStation API Settings', 'ffl-hub'), 'primary', 'fflhub_shipstation_save_settings'); ?>
            </form>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['fflhub_shipstation_settings_form'])) {
            return;
        }

        if (!current_user_can(ShippingAdminPage::CAPABILITY)) {
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
        $previous_mode = ShipStationOptions::mode();
        $posted_mode = in_array((string) ($input['mode'] ?? 'sandbox'), ['sandbox', 'production'], true)
            ? (string) $input['mode']
            : 'sandbox';
        $mode_changed = $posted_mode !== $previous_mode;
        if ($mode_changed) {
            $input['enabled_carrier_ids'] = [];
            $input['firearm_carrier_ids'] = [];
        }

        $clear_keys = [];
        if (isset($_POST['shipstation_clear_sandbox_api_key'])) {
            $clear_keys[] = 'sandbox';
        }
        if (isset($_POST['shipstation_clear_production_api_key'])) {
            $clear_keys[] = 'production';
        }
        ShipStationOptions::save($input, $clear_keys);

        if ($mode_changed || isset($_POST['fflhub_shipstation_refresh_carriers'])) {
            $cache = (new ShipStationCarrierCache(new ShipStationClient()))->refresh();
            if (is_wp_error($cache)) {
                $this->store_result('error', 'ShipStation connection failed: ' . $cache->get_error_message());
            } elseif ($mode_changed) {
                $this->store_result('success', 'ShipStation API settings saved. Mode changed to ' . ucfirst($posted_mode) . '. Carriers refreshed: ' . count((array) ($cache['carriers'] ?? [])) . '.');
            } else {
                $this->store_result('success', 'ShipStation connection succeeded. Carriers refreshed: ' . count((array) ($cache['carriers'] ?? [])) . '.');
            }
        } else {
            $this->store_result('success', 'ShipStation API settings saved.');
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

        echo '<table class="widefat striped fflhub-shipping-carriers"><thead><tr>';
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

    /**
     * Carrier selections belong to one ShipStation mode/account. When the saved
     * IDs do not exist in the current cache, show the same fallback the runtime
     * uses instead of rendering a page where every current carrier looks off.
     *
     * @param string[] $selected_ids
     * @param array<int,array<string,mixed>> $carriers
     * @return string[]
     */
    private function selection_for_current_cache(array $selected_ids, array $carriers): array
    {
        if (empty($selected_ids) || empty($carriers)) {
            return $selected_ids;
        }

        $known_ids = [];
        foreach ($carriers as $carrier) {
            if (!is_array($carrier)) {
                continue;
            }
            $id = (string) ($carrier['carrier_id'] ?? '');
            if ($id !== '') {
                $known_ids[] = $id;
            }
        }

        if (empty($known_ids)) {
            return $selected_ids;
        }

        $current = array_values(array_intersect($selected_ids, array_unique($known_ids)));
        return empty($current) ? [] : $current;
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
